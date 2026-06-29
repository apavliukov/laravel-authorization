<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Concerns;

use AlexPavliukov\Authorization\Support\ModelHasRolesQuery;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Query scopes for filtering models by team-aware role assignments, without
 * switching the active permissions team.
 */
trait HasTeamAwareRoles
{
    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopeWhereHasGlobalRole(Builder $query, BackedEnum|string $role): Builder
    {
        resolve(ModelHasRolesQuery::class)
            ->applyScope($query, ModelHasRolesQuery::roleName($role), null, true);

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopeWhereHasRoleInTeam(Builder $query, BackedEnum|string $role, int|string $teamId): Builder
    {
        resolve(ModelHasRolesQuery::class)
            ->applyScope($query, ModelHasRolesQuery::roleName($role), $teamId, false);

        return $query;
    }
}
