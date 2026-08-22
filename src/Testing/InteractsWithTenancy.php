<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Testing;

use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\Tenancy\MissingTenantContextException;
use AlexPavliukov\Authorization\Tenancy\TenantMembership;
use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Test helpers for tenant context. Expects the host test case to provide
 * `actingAs()` (Laravel's testing base) and PHPUnit assertions.
 */
trait InteractsWithTenancy
{
    /** Authenticate, activate the tenant, and optionally grant a role inside it. */
    protected function actingAsInTenant(Authenticatable $user, Model|int|string $tenant, BackedEnum|string|null $role = null): static
    {
        if ($role !== null) {
            resolve(TenantMembership::class)->add($user, $tenant, $role);
        }

        resolve(CurrentTenant::class)->set($tenant);

        return $this->actingAs($user);
    }

    protected function withoutTenant(callable $callback): mixed
    {
        return resolve(CurrentTenant::class)->withoutTenant($callback);
    }

    protected function forTenant(Model|int|string $tenant, callable $callback): mixed
    {
        return resolve(CurrentTenant::class)->forTenant($tenant, $callback);
    }

    /**
     * The fail-closed contract, per model: outside tenant context the model
     * yields nothing (non-strict) or throws (strict).
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function assertFailsClosed(string $modelClass): void
    {
        $tenant = resolve(CurrentTenant::class);
        $previous = $tenant->id();
        $tenant->forget();

        try {
            $count = $modelClass::query()->count();
        } catch (MissingTenantContextException) {
            $this->addToAssertionCount(1);

            return;
        } finally {
            if ($previous !== null) {
                $tenant->set($previous);
            }
        }

        $this->assertSame(0, $count, "[{$modelClass}] returned {$count} rows outside tenant context; it is not fenced by TenantScope.");
    }
}
