<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Attribute-tenancy policy base: fences a model to the current user's tenant.
 * The app declares once how to read the tenant from the user
 * (`Authorization::resolveTenantUsing()`) and the default owning column
 * (`Authorization::tenantColumn()`). A policy whose model points at its tenant
 * through a relation overrides `tenantKey()`; the rest need only `getModelClass()`.
 */
abstract readonly class TenantScopedPolicy extends AbstractPolicy
{
    protected function ownsModel(Authenticatable $user, Model $model): bool
    {
        $tenant = resolve(AuthorizationManager::class)->currentTenant($user);

        return $tenant !== null && $tenant === $this->tenantKey($model);
    }

    protected function tenantKey(Model $model): int|string|null
    {
        $value = $model->getAttribute(resolve(AuthorizationManager::class)->tenantColumnName());

        return is_int($value) || is_string($value) ? $value : null;
    }
}
