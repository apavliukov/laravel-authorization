<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\Tenancy\Tenancy;
use AlexPavliukov\Authorization\Tenancy\TenantMembership;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TenancyTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('tenancy')]
#[CoversClass(TenantMembership::class)]
#[CoversClass(Tenancy::class)]
final class TenantMembershipTest extends TenancyTestCase
{
    #[Test]
    public function add_change_and_remove_keep_the_active_tenant_untouched(): void
    {
        $acme = Organization::query()->create(['name' => 'Acme']);
        $globex = Organization::query()->create(['name' => 'Globex']);
        $user = User::factory()->create();
        resolve(CurrentTenant::class)->set($globex);

        $membership = resolve(TenantMembership::class);

        $membership->add($user, $acme, Role::MEMBER);
        $this->assertSame('member', $membership->roleFor($user, $acme));
        $this->assertTrue($membership->isMember($user, $acme));

        $membership->changeRole($user, $acme, Role::EDITOR);
        $this->assertSame('editor', $membership->roleFor($user, $acme));

        $membership->remove($user, $acme);
        $this->assertNull($membership->roleFor($user, $acme));
        $this->assertFalse($membership->isMember($user, $acme));

        $this->assertSame($globex->id, resolve(CurrentTenant::class)->id());
        $this->assertSame($globex->id, getPermissionsTeamId());
    }

    #[Test]
    public function the_facade_proxies_the_current_tenant(): void
    {
        $acme = Organization::query()->create(['name' => 'Acme']);
        Tenancy::set($acme);

        $this->assertSame($acme->id, Tenancy::id());
        $this->assertTrue(Tenancy::withoutTenant(static fn (): bool => Tenancy::isBypassed()));
    }
}
