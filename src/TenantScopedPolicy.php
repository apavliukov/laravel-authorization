<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization;

use AlexPavliukov\Authorization\Support\ScalarKey;
use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Attribute-tenancy policy base: fences a model to the current tenant by its
 * `tenancy.foreign_key` column. A policy whose model points at its tenant through
 * a relation overrides `tenantKey()`; the rest need only `getModelClass()`.
 */
abstract readonly class TenantScopedPolicy extends AbstractPolicy
{
    protected function ownsModel(Authenticatable $user, Model $model): bool
    {
        return ScalarKey::same(resolve(CurrentTenant::class)->id(), $this->tenantKey($model));
    }

    protected function tenantKey(Model $model): int|string|null
    {
        return ScalarKey::normalize($model->getAttribute(resolve(CurrentTenant::class)->foreignKey()));
    }
}
