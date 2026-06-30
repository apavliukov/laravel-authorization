<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Support;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Team-aware reads against Spatie's `model_has_roles` pivot, without mutating the
 * active permissions team. Encapsulates the Spatie column/table naming so app code
 * never hand-writes the EXISTS query.
 */
final class ModelHasRolesQuery
{
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

    private function constrain(QueryBuilder $query, string $roleName, TeamScope $scope, int|string|null $teamId): void
    {
        $query->whereIn($this->roleKey(), function (QueryBuilder $roles) use ($roleName): void {
            $roles->select('id')
                ->from($this->rolesTable())
                ->where('name', $roleName)
                ->where('guard_name', $this->guard());
        });

        if ($scope === TeamScope::Global) {
            $query->whereNull($this->teamKey());
        } elseif ($scope === TeamScope::Team) {
            $query->where($this->teamKey(), $teamId);
        }
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
