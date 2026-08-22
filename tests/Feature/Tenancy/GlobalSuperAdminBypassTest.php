<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\Support\RoleBypass;
use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\Testing\InteractsWithAuthorization;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\SystemAbility;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TenancyTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('tenancy')]
#[CoversClass(RoleBypass::class)]
final class GlobalSuperAdminBypassTest extends TenancyTestCase
{
    use InteractsWithAuthorization;

    #[Test]
    public function a_global_super_admin_bypasses_while_a_tenant_is_active(): void
    {
        $organization = Organization::query()->create(['name' => 'Acme']);
        $admin = User::factory()->create();
        $this->assignRoleInTeam($admin, Role::ADMIN, null);

        resolve(CurrentTenant::class)->set($organization);

        $this->assertTrue($admin->can(SystemAbility::ACCESS_PLATFORM_ADMIN->value));
    }

    #[Test]
    public function a_tenant_scoped_super_admin_role_does_not_bypass(): void
    {
        $organization = Organization::query()->create(['name' => 'Acme']);
        $user = User::factory()->create();
        $this->assignRoleInTeam($user, Role::ADMIN, $organization->id);

        resolve(CurrentTenant::class)->set($organization);

        $this->assertFalse($user->can(SystemAbility::ACCESS_PLATFORM_ADMIN->value));
    }
}
