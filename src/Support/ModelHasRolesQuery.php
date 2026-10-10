<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Support;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RuntimeException;

/**
 * Team-aware reads against Spatie's `model_has_roles` pivot, without mutating the
 * active permissions team. Encapsulates the Spatie column/table naming so app code
 * never hand-writes the EXISTS query.
 */
final class ModelHasRolesQuery
{
    /**
     * Request-scoped memo (the service is bound `scoped`, so it is flushed per
     * request / queue job). Keyed by model identity + query shape.
     *
     * @var array<string, bool>
     */
    private array $hasCache = [];

    /** @var array<string, list<string>> */
    private array $rolesCache = [];

    /** @var array<string, list<int|string>> */
    private array $teamIdsCache = [];

    public static function roleName(BackedEnum|string $role): string
    {
        return $role instanceof BackedEnum ? (string) $role->value : $role;
    }

    /**
     * Whether the user holds the named role under the given team scope: globally
     * (`team_id IS NULL`), within a specific team, or in any team.
     */
    public function userHasRole(Model $user, string $roleName, TeamScope $scope, int|string|null $teamId = null): bool
    {
        $key = $this->cacheKey($user).'|has|'.$roleName.'|'.$scope->name.'|'.($teamId ?? 'null');

        return $this->hasCache[$key] ??= $this->queryUserHasRole($user, $roleName, $scope, $teamId);
    }

    private function queryUserHasRole(Model $user, string $roleName, TeamScope $scope, int|string|null $teamId): bool
    {
        $query = $user->getConnection()->table($this->pivotTable());
        $query->where($this->morphKey(), $user->getKey())
            ->where('model_type', $user->getMorphClass());

        $this->constrain($query, $roleName, $scope, $teamId);

        return $query->exists();
    }

    /**
     * Keep only models holding the named role under the given team scope, via a
     * correlated EXISTS subquery — leaves the active team untouched.
     *
     * @param  EloquentBuilder<Model>  $query
     */
    public function applyScope(EloquentBuilder $query, string $roleName, TeamScope $scope, int|string|null $teamId = null): void
    {
        $model = $query->getModel();
        $pivotTable = $this->pivotTable();
        $qualifiedMorphKey = $pivotTable.'.'.$this->morphKey();

        $query->whereExists(function (QueryBuilder $subQuery) use ($model, $pivotTable, $qualifiedMorphKey, $roleName, $scope, $teamId): void {
            $subQuery->from($pivotTable)
                ->whereColumn($qualifiedMorphKey, $model->getQualifiedKeyName())
                ->where('model_type', $model->getMorphClass());

            $this->constrain($subQuery, $roleName, $scope, $teamId);
        });
    }

    /**
     * The names of the roles the user holds in the given team (or globally when
     * $teamId is null).
     *
     * @return list<string>
     */
    public function rolesInTeam(Model $user, int|string|null $teamId): array
    {
        $key = $this->cacheKey($user).'|roles|'.($teamId ?? 'null');

        return $this->rolesCache[$key] ??= $this->queryRolesInTeam($user, $teamId);
    }

    /**
     * The distinct teams the user holds any role in (global assignments excluded).
     *
     * @return list<int|string>
     */
    public function teamIdsOf(Model $user): array
    {
        $key = $this->cacheKey($user);

        return $this->teamIdsCache[$key] ??= $this->queryTeamIdsOf($user);
    }

    /** Delete every role row of the user — in every team and globally. */
    public function detachAll(Model $user): void
    {
        $user->getConnection()->table($this->pivotTable())
            ->where($this->morphKey(), $user->getKey())
            ->where('model_type', $user->getMorphClass())
            ->delete();

        $user->unsetRelation('roles');
    }

    /**
     * The tenants (Spatie teams) the user holds any role in, as a relation —
     * for eager loading and pivot-aware queries. DISTINCT collapses several roles
     * in one team; `count()` compiles to `count(*)` and ignores it — use `exists()`
     * or `count('<table>.id')`.
     *
     * @param  class-string<Model>  $tenantClass
     * @return MorphToMany<Model, Model>
     */
    public function tenantsOf(Model $user, string $tenantClass): MorphToMany
    {
        return $user->morphToMany($tenantClass, 'model', $this->pivotTable(), $this->morphKey(), $this->teamKey())
            ->whereIn($this->pivotTable().'.'.$this->roleKey(), $this->guardRoleIds())
            ->distinct();
    }

    /**
     * The users holding any role in the tenant's team, as a relation. The user
     * class is the one behind the guard the roles are filtered by. Same
     * `count()` caveat as tenantsOf().
     *
     * @return MorphToMany<Model, Model>
     */
    public function membersOf(Model $tenant): MorphToMany
    {
        /** @var class-string<Model>|null $userClass */
        $userClass = getModelForGuard($this->guard());

        if ($userClass === null) {
            throw new RuntimeException("No user model behind guard [{$this->guard()}].");
        }

        return $tenant->morphedByMany($userClass, 'model', $this->pivotTable(), $this->teamKey(), $this->morphKey())
            ->whereIn($this->pivotTable().'.'.$this->roleKey(), $this->guardRoleIds())
            ->distinct();
    }

    /**
     * Drop the memoized reads for a single user — call after mutating that user's
     * roles within the same request, before reading them again.
     */
    public function forget(Model $user): void
    {
        $key = $this->cacheKey($user);
        $prefix = $key.'|';

        foreach (array_keys($this->hasCache) as $cached) {
            if (str_starts_with($cached, $prefix)) {
                unset($this->hasCache[$cached]);
            }
        }

        foreach (array_keys($this->rolesCache) as $cached) {
            if (str_starts_with($cached, $prefix)) {
                unset($this->rolesCache[$cached]);
            }
        }

        unset($this->teamIdsCache[$key]);
    }

    /** @return list<int|string> */
    private function queryTeamIdsOf(Model $user): array
    {
        /** @var list<int|string> $ids */
        $ids = $user->getConnection()->table($this->pivotTable())
            ->where($this->morphKey(), $user->getKey())
            ->where('model_type', $user->getMorphClass())
            ->whereNotNull($this->teamKey())
            ->whereIn($this->roleKey(), $this->guardRoleIds())
            ->distinct()
            ->pluck($this->teamKey())
            ->all();

        return $ids;
    }

    /** @return list<string> */
    private function queryRolesInTeam(Model $user, int|string|null $teamId): array
    {
        $pivotTable = $this->pivotTable();
        $rolesTable = $this->rolesTable();

        $query = $user->getConnection()->table($pivotTable)
            ->join($rolesTable, $rolesTable.'.id', '=', $pivotTable.'.'.$this->roleKey())
            ->where($pivotTable.'.'.$this->morphKey(), $user->getKey())
            ->where($pivotTable.'.model_type', $user->getMorphClass())
            ->where($rolesTable.'.guard_name', $this->guard());

        if ($teamId === null) {
            $query->whereNull($pivotTable.'.'.$this->teamKey());
        } else {
            $query->where($pivotTable.'.'.$this->teamKey(), $teamId);
        }

        /** @var list<string> $names */
        $names = $query->pluck($rolesTable.'.name')->all();

        return $names;
    }

    private function cacheKey(Model $user): string
    {
        $key = $user->getKey();

        return $user->getMorphClass().'|'.(is_scalar($key) ? (string) $key : '');
    }

    private function constrain(QueryBuilder $query, string $roleName, TeamScope $scope, int|string|null $teamId): void
    {
        $query->whereIn($this->roleKey(), $this->guardRoleIds($roleName));

        if ($scope === TeamScope::Global) {
            $query->whereNull($this->teamKey());
        } elseif ($scope === TeamScope::Team) {
            $query->where($this->teamKey(), $teamId);
        }
    }

    /**
     * Subquery of role ids under the guard every read here is scoped to — a role
     * row from another guard is never a membership.
     *
     * @return Closure(QueryBuilder): void
     */
    private function guardRoleIds(?string $roleName = null): Closure
    {
        return function (QueryBuilder $roles) use ($roleName): void {
            $roles->select('id')
                ->from($this->rolesTable())
                ->where('guard_name', $this->guard())
                ->when($roleName !== null, static fn (QueryBuilder $q) => $q->where('name', $roleName));
        };
    }

    private function pivotTable(): string
    {
        return $this->config('permission.table_names.model_has_roles', 'model_has_roles');
    }

    private function rolesTable(): string
    {
        return $this->config('permission.table_names.roles', 'roles');
    }

    private function morphKey(): string
    {
        return $this->config('permission.column_names.model_morph_key', 'model_id');
    }

    private function teamKey(): string
    {
        return $this->config('permission.column_names.team_foreign_key', 'team_id');
    }

    private function roleKey(): string
    {
        return $this->config('permission.column_names.role_pivot_key', 'role_id');
    }

    private function guard(): string
    {
        return $this->config('auth.defaults.guard', 'web');
    }

    private function config(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }
}
