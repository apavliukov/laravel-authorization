<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Unit;

use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

#[Group('policies')]
#[CoversClass(AuthorizationManager::class)]
final class AuthorizationManagerTest extends TestCase
{
    #[Test]
    public function it_round_trips_the_role_enum(): void
    {
        $manager = new AuthorizationManager;
        $manager->useRoleEnum(Role::class);

        $this->assertSame(Role::class, $manager->roleEnum());
    }

    #[Test]
    public function it_throws_when_role_enum_is_not_configured(): void
    {
        $manager = new AuthorizationManager;

        $this->expectException(RuntimeException::class);

        $manager->roleEnum();
    }

    #[Test]
    public function it_round_trips_authorizable_models(): void
    {
        $manager = new AuthorizationManager;
        $manager->authorizableModels([User::class]);

        $this->assertSame([User::class], $manager->models());
    }

    #[Test]
    public function it_returns_only_super_admin_roles(): void
    {
        $manager = new AuthorizationManager;
        $manager->useRoleEnum(Role::class);

        $this->assertSame([Role::ADMIN], $manager->superAdminRoles());
    }
}
