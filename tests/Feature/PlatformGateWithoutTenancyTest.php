<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature;

use AlexPavliukov\Authorization\AuthorizationServiceProvider;
use AlexPavliukov\Authorization\Concerns\HasAppRole;
use AlexPavliukov\Authorization\Tenancy\EnsurePlatformArea;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/** No teams, no tenancy: the platform gate and the identity trait still work. */
#[Group('policies')]
#[CoversClass(AuthorizationServiceProvider::class)]
#[CoversClass(HasAppRole::class)]
final class PlatformGateWithoutTenancyTest extends TestCase
{
    #[Test]
    public function the_platform_gate_is_registered_and_granted_only_through_the_bypass(): void
    {
        $this->assertNull(config('authorization.tenancy.model'));

        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create();

        $this->assertTrue($admin->can(EnsurePlatformArea::GATE));
        $this->assertTrue($admin->is_platform_admin);
        $this->assertSame(Role::ADMIN, $admin->app_role);
        $this->assertFalse($member->can(EnsurePlatformArea::GATE));
        $this->assertFalse($member->is_platform_admin);
        $this->assertSame(Role::MEMBER, $member->app_role);
    }
}
