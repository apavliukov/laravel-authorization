<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use AlexPavliukov\Authorization\Tests\TenancyTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('tenancy')]
#[CoversClass(CurrentTenant::class)]
final class CurrentTenantTest extends TenancyTestCase
{
    #[Test]
    public function it_starts_empty_and_not_bypassed(): void
    {
        $tenant = resolve(CurrentTenant::class);

        $this->assertNull($tenant->id());
        $this->assertNull($tenant->get());
        $this->assertFalse($tenant->isBypassed());
    }

    #[Test]
    public function set_stores_the_tenant_and_activates_its_permissions_team(): void
    {
        $organization = Organization::query()->create(['name' => 'Acme']);

        $tenant = resolve(CurrentTenant::class);
        $tenant->set($organization);

        $this->assertSame($organization->id, $tenant->id());
        $this->assertTrue($organization->is($tenant->get()));
        $this->assertSame($organization->id, getPermissionsTeamId());
    }

    #[Test]
    public function set_accepts_a_key_and_loads_the_model_lazily(): void
    {
        $organization = Organization::query()->create(['name' => 'Acme']);

        $tenant = resolve(CurrentTenant::class);
        $tenant->set($organization->id);

        $this->assertSame('Acme', $tenant->get()?->getAttribute('name'));
    }

    #[Test]
    public function forget_clears_the_tenant_and_the_permissions_team(): void
    {
        $tenant = resolve(CurrentTenant::class);
        $tenant->set(Organization::query()->create(['name' => 'Acme']));
        $tenant->forget();

        $this->assertNull($tenant->id());
        $this->assertNull(getPermissionsTeamId());
    }

    #[Test]
    public function without_tenant_bypasses_for_the_callback_only_and_restores_after_a_throw(): void
    {
        $organization = Organization::query()->create(['name' => 'Acme']);
        $tenant = resolve(CurrentTenant::class);
        $tenant->set($organization);

        $seen = $tenant->withoutTenant(static fn (): bool => resolve(CurrentTenant::class)->isBypassed());

        $this->assertTrue($seen);
        $this->assertFalse($tenant->isBypassed());
        $this->assertSame($organization->id, $tenant->id());

        try {
            $tenant->withoutTenant(static function (): void {
                throw new \LogicException('boom');
            });
        } catch (\LogicException) {
        }

        $this->assertFalse($tenant->isBypassed());
        $this->assertSame($organization->id, $tenant->id());
    }

    #[Test]
    public function for_tenant_switches_the_tenant_for_the_callback_only(): void
    {
        $acme = Organization::query()->create(['name' => 'Acme']);
        $globex = Organization::query()->create(['name' => 'Globex']);
        $tenant = resolve(CurrentTenant::class);
        $tenant->set($acme);

        $seen = $tenant->forTenant($globex, static fn (): array => [
            resolve(CurrentTenant::class)->id(),
            getPermissionsTeamId(),
        ]);

        $this->assertSame([$globex->id, $globex->id], $seen);
        $this->assertSame($acme->id, $tenant->id());
        $this->assertSame($acme->id, getPermissionsTeamId());
    }

    #[Test]
    public function for_tenant_suspends_an_outer_bypass_and_restores_it(): void
    {
        $acme = Organization::query()->create(['name' => 'Acme']);
        $tenant = resolve(CurrentTenant::class);

        $seen = $tenant->withoutTenant(static fn (): array => [
            resolve(CurrentTenant::class)->forTenant($acme, static fn (): bool => resolve(CurrentTenant::class)->isBypassed()),
            resolve(CurrentTenant::class)->isBypassed(),
        ]);

        $this->assertSame([false, true], $seen);
    }

    #[Test]
    public function tenancy_is_reported_as_configured_from_the_config_model(): void
    {
        $this->assertTrue(resolve(CurrentTenant::class)->isConfigured());

        config()->set('authorization.tenancy.model', null);

        $this->assertFalse(resolve(CurrentTenant::class)->isConfigured());
    }
}
