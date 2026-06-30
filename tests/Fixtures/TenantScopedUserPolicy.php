<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures;

use AlexPavliukov\Authorization\TenantScopedPolicy;

/**
 * Stands in for an app's attribute-tenancy policy: fences User to the actor's
 * tenant via the default owning column (no `tenantKey()` override needed).
 */
final readonly class TenantScopedUserPolicy extends TenantScopedPolicy
{
    protected function getModelClass(): string
    {
        return User::class;
    }
}
