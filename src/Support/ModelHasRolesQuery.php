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
     * Whether the user holds the named role as a global assignment
     * (`team_id IS NULL`) or within a specific team.
     */
    public function userHasRole(Model $user, string $roleName, int|string|null $teamId, bool $global): bool
    {
        $query = $user->getConnection()->table($this->pivotTable());
        $query->where($this->morphKey(), $user->getKey())
            ->where('model_type', $user->getMorphClass());

        $this->constrain($query, $roleName, $teamId, $global);

        return $query->exists();
    }

    /**
     * Keep only models holding the named role globally or within the given team,
     * via a correlated EXISTS subquery — leaves the active team untouched.
     *
     * @param  EloquentBuilder<Model>  $query
     */
    public function applyScope(EloquentBuilder $query, string $roleName, int|string|null $teamId, bool $global): void
    {
        $model = $query->getModel();
        $pivotTable = $this->pivotTable();
        $qualifiedMorphKey = $pivotTable.'.'.$this->morphKey();

        $query->whereExists(function (QueryBuilder $subQuery) use ($model, $pivotTable, $qualifiedMorphKey, $roleName, $teamId, $global): void {
            $subQuery->from($pivotTable)
                ->whereColumn($qualifiedMorphKey, $model->getQualifiedKeyName())
                ->where('model_type', $model->getMorphClass());

            $this->constrain($subQuery, $roleName, $teamId, $global);
        });
    }

    private function constrain(QueryBuilder $query, string $roleName, int|string|null $teamId, bool $global): void
    {
        $query->whereIn($this->roleKey(), function (QueryBuilder $roles) use ($roleName): void {
            $roles->select('id')
                ->from($this->rolesTable())
                ->where('name', $roleName)
                ->where('guard_name', $this->guard());
        });

        if ($global) {
            $query->whereNull($this->teamKey());

            return;
        }

        $query->where($this->teamKey(), $teamId);
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
