<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Unit;

use AlexPavliukov\Authorization\Authorization;
use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Concerns\HasTeamAwareRoles;
use AlexPavliukov\Authorization\Support\ModelHasRolesQuery;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TeamsTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('policies')]
#[CoversClass(AuthorizationManager::class)]
#[CoversClass(ModelHasRolesQuery::class)]
#[CoversTrait(HasTeamAwareRoles::class)]
final class TeamAwareRolesTest extends TeamsTestCase
{
    #[Test]
    public function it_detects_a_global_role_assignment(): void
    {
        $user = $this->createUser('global@example.test');
        Authorization::withTeam(null, static fn (): mixed => $user->assignRole(Role::ADMIN->value));

        $manager = resolve(AuthorizationManager::class);

        $this->assertTrue($manager->userHasGlobalRole($user, Role::ADMIN));
        $this->assertFalse($manager->userHasRoleInTeam($user, Role::ADMIN, 5));
    }

    #[Test]
    public function it_detects_a_team_scoped_role_assignment(): void
    {
        $user = $this->createUser('team@example.test');
        Authorization::withTeam(5, static fn (): mixed => $user->assignRole(Role::EDITOR->value));

        $manager = resolve(AuthorizationManager::class);

        $this->assertTrue($manager->userHasRoleInTeam($user, Role::EDITOR, 5));
        $this->assertFalse($manager->userHasRoleInTeam($user, Role::EDITOR, 9));
        $this->assertFalse($manager->userHasGlobalRole($user, Role::EDITOR));
    }

    #[Test]
    public function scope_filters_users_holding_a_global_role(): void
    {
        $admin = $this->createUser('admin@example.test');
        $this->createUser('member@example.test');
        Authorization::withTeam(null, static fn (): mixed => $admin->assignRole(Role::ADMIN->value));

        $ids = User::query()->whereHasGlobalRole(Role::ADMIN)->pluck('id')->all();

        $this->assertSame([$admin->getKey()], $ids);
    }

    #[Test]
    public function scope_filters_users_holding_a_role_within_a_team(): void
    {
        $inTeam = $this->createUser('in-team@example.test');
        $elsewhere = $this->createUser('elsewhere@example.test');
        Authorization::withTeam(5, static fn (): mixed => $inTeam->assignRole(Role::EDITOR->value));
        Authorization::withTeam(9, static fn (): mixed => $elsewhere->assignRole(Role::EDITOR->value));

        $ids = User::query()->whereHasRoleInTeam(Role::EDITOR, 5)->pluck('id')->all();

        $this->assertSame([$inTeam->getKey()], $ids);
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Test',
            'email' => $email,
            'password' => 'secret',
        ]);
    }
}
