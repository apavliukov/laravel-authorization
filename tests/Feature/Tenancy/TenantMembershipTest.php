<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\Authorization;
use AlexPavliukov\Authorization\Concerns\HasAppRole;
use AlexPavliukov\Authorization\Tenancy\Concerns\HasMembers;
use AlexPavliukov\Authorization\Tenancy\Concerns\HasTenantMemberships;
use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\Tenancy\MembershipPolicyException;
use AlexPavliukov\Authorization\Tenancy\Tenancy;
use AlexPavliukov\Authorization\Tenancy\TenantMembership;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TenancyTestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role as SpatieRole;

#[Group('tenancy')]
#[CoversClass(TenantMembership::class)]
#[CoversClass(Tenancy::class)]
#[CoversClass(HasMembers::class)]
#[CoversClass(HasTenantMemberships::class)]
#[CoversClass(HasAppRole::class)]
final class TenantMembershipTest extends TenancyTestCase
{
    private Organization $acme;

    private Organization $globex;

    private TenantMembership $membership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::query()->create(['name' => 'Acme']);
        $this->globex = Organization::query()->create(['name' => 'Globex']);
        $this->membership = resolve(TenantMembership::class);
    }

    #[Test]
    public function add_change_and_remove_keep_the_active_tenant_untouched(): void
    {
        $user = User::factory()->create();
        resolve(CurrentTenant::class)->set($this->globex);

        $this->membership->add($user, $this->acme, Role::MEMBER);
        $this->assertSame('member', $this->membership->roleFor($user, $this->acme));
        $this->assertTrue($this->membership->isMember($user, $this->acme));

        $this->membership->changeRole($user, $this->acme, Role::EDITOR);
        $this->assertSame('editor', $this->membership->roleFor($user, $this->acme));

        $this->membership->remove($user, $this->acme);
        $this->assertNull($this->membership->roleFor($user, $this->acme));
        $this->assertFalse($this->membership->isMember($user, $this->acme));

        $this->assertSame($this->globex->id, resolve(CurrentTenant::class)->id());
        $this->assertSame($this->globex->id, getPermissionsTeamId());
    }

    #[Test]
    public function tenant_ids_and_relations_read_model_has_roles_and_ignore_global_roles(): void
    {
        $user = User::factory()->create();
        $this->membership->assignGlobal($user, Role::ADMIN);
        $this->assertSame([], $this->membership->tenantIdsOf($user));

        $this->membership->add($user, $this->acme, Role::MEMBER);
        $this->membership->add($user, $this->acme, Role::EDITOR);   // two roles, one membership
        $this->membership->add($user, $this->globex, Role::MEMBER);

        $this->assertEqualsCanonicalizing([$this->acme->id, $this->globex->id], $this->membership->tenantIdsOf($user));
        $this->assertEqualsCanonicalizing([$this->acme->id, $this->globex->id], $user->tenants()->pluck('id')->all());
        $this->assertSame([$user->id], $this->acme->members()->pluck('id')->all());
        $this->assertSame([], Organization::query()->create(['name' => 'Empty'])->members()->pluck('id')->all());
    }

    #[Test]
    public function a_membership_write_refreshes_tenant_ids_even_with_warm_role_memos(): void
    {
        $user = User::factory()->create();
        $this->membership->add($user, $this->acme, Role::MEMBER);

        // Warm every memo the resolver touches: roles-in-team, has-role, team ids.
        $this->assertTrue($this->membership->isMember($user, $this->acme));
        $this->assertFalse(Authorization::isGlobalSuperAdmin($user));
        $this->assertSame([$this->acme->id], $this->membership->tenantIdsOf($user));

        $this->membership->add($user, $this->globex, Role::MEMBER);
        $this->assertEqualsCanonicalizing([$this->acme->id, $this->globex->id], $this->membership->tenantIdsOf($user));

        $this->membership->remove($user, $this->acme);
        $this->assertSame([$this->globex->id], $this->membership->tenantIdsOf($user));
        $this->assertSame(1, $this->globex->members()->count('users.id'));
    }

    #[Test]
    public function a_role_under_another_guard_is_not_a_membership(): void
    {
        $user = User::factory()->create();
        $apiRole = SpatieRole::findOrCreate(Role::MEMBER->value, 'api');
        DB::table('model_has_roles')->insert([
            'role_id' => $apiRole->id,
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->id,
            'team_id' => $this->acme->id,
        ]);

        $this->assertFalse($this->membership->isMember($user, $this->acme));
        $this->assertSame([], $this->membership->tenantIdsOf($user));
        $this->assertSame([], $user->tenants()->pluck('id')->all());
        $this->assertSame([], $this->acme->members()->pluck('id')->all());
    }

    #[Test]
    public function remove_global_drops_only_the_global_row(): void
    {
        $user = User::factory()->create();
        $this->membership->assignGlobal($user, Role::ADMIN);
        $this->membership->add($user, $this->acme, Role::MEMBER);
        $this->assertSame(Role::ADMIN, $user->app_role);

        $this->membership->removeGlobal($user, Role::ADMIN);

        $this->assertFalse(Authorization::isGlobalSuperAdmin($user));
        $this->assertTrue($this->membership->isMember($user, $this->acme));
        $this->assertSame(Role::MEMBER, $user->app_role);   // same instance, memo flushed
    }

    #[Test]
    public function single_membership_refuses_a_second_tenant_but_ignores_global_roles(): void
    {
        config(['authorization.tenancy.memberships' => 'single']);
        $user = User::factory()->create();
        $this->membership->assignGlobal($user, Role::ADMIN);
        $this->membership->add($user, $this->acme, Role::MEMBER);
        $this->membership->changeRole($user, $this->acme, Role::EDITOR);   // same tenant is fine

        $this->assertThrows(fn () => $this->membership->add($user, $this->globex, Role::MEMBER), MembershipPolicyException::class);
        $this->assertThrows(fn () => $this->membership->changeRole($user, $this->globex, Role::MEMBER), MembershipPolicyException::class);

        $this->assertSame([$this->acme->id], $this->membership->tenantIdsOf($user));
    }

    #[Test]
    public function multiple_memberships_are_the_default(): void
    {
        $user = User::factory()->create();
        $this->membership->add($user, $this->acme, Role::MEMBER);
        $this->membership->add($user, $this->globex, Role::MEMBER);

        $this->assertCount(2, $this->membership->tenantIdsOf($user));
    }

    #[Test]
    public function the_identity_trait_is_team_agnostic(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $this->membership->assignGlobal($admin, Role::ADMIN);
        $this->membership->add($member, $this->acme, Role::MEMBER);
        $this->membership->add($member, $this->globex, Role::ADMIN);   // tenant-scoped copy: not platform admin

        resolve(CurrentTenant::class)->set($this->acme);

        $this->assertTrue($admin->is_platform_admin);
        $this->assertSame(Role::ADMIN, $admin->app_role);
        $this->assertFalse($member->is_platform_admin);
        $this->assertSame(Role::ADMIN, $member->app_role);   // highest role held anywhere

        $this->membership->remove($member, $this->globex);
        $this->assertSame(Role::MEMBER, $member->app_role);   // same instance, no stale attribute cache
    }

    #[Test]
    public function the_facade_proxies_the_current_tenant(): void
    {
        Tenancy::set($this->acme);

        $this->assertSame($this->acme->id, Tenancy::id());
        $this->assertTrue(Tenancy::withoutTenant(static fn (): bool => Tenancy::isBypassed()));
    }
}
