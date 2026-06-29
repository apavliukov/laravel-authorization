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

    #[Test]
    public function with_team_runs_the_callback_under_the_team_and_restores_the_previous(): void
    {
        setPermissionsTeamId(3);
        $manager = new AuthorizationManager;

        $teamInside = $manager->withTeam(7, static fn (): int|string|null => getPermissionsTeamId());

        $this->assertSame(7, $teamInside);
        $this->assertSame(3, getPermissionsTeamId());

        setPermissionsTeamId(null);
    }

    #[Test]
    public function with_team_restores_the_previous_team_when_the_callback_throws(): void
    {
        setPermissionsTeamId(3);
        $manager = new AuthorizationManager;

        try {
            $manager->withTeam(7, static function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(3, getPermissionsTeamId());

        setPermissionsTeamId(null);
    }
}
