<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy\Concerns;

use AlexPavliukov\Authorization\Support\ModelHasRolesQuery;
use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * For the user model: the tenants it holds any role in, read straight from
 * `model_has_roles` (global roles, `team_id NULL`, are not memberships). `count()`
 * ignores the DISTINCT — use `exists()` or `count('<tenants>.id')`.
 */
trait HasTenantMemberships
{
    /** @return MorphToMany<Model, Model> */
    public function tenants(): MorphToMany
    {
        return resolve(ModelHasRolesQuery::class)->tenantsOf($this, resolve(CurrentTenant::class)->modelClass());
    }
}
