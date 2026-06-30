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
use Illuminate\Support\Facades\DB;
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

    #[Test]
    public function it_detects_a_role_held_in_any_team(): void
    {
        $user = $this->createUser('any-team@example.test');
        $without = $this->createUser('without@example.test');
        Authorization::withTeam(7, static fn (): mixed => $user->assignRole(Role::EDITOR->value));

        $manager = resolve(AuthorizationManager::class);

        $this->assertTrue($manager->userHasRole($user, Role::EDITOR));
        $this->assertFalse($manager->userHasRole($without, Role::EDITOR));
    }

    #[Test]
    public function scope_filters_users_holding_a_role_in_any_team(): void
    {
        $inTeam5 = $this->createUser('team5@example.test');
        $inTeam9 = $this->createUser('team9@example.test');
        $this->createUser('roleless@example.test');
        Authorization::withTeam(5, static fn (): mixed => $inTeam5->assignRole(Role::EDITOR->value));
        Authorization::withTeam(9, static fn (): mixed => $inTeam9->assignRole(Role::EDITOR->value));

        $ids = User::query()->whereHasRole(Role::EDITOR)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$inTeam5->getKey(), $inTeam9->getKey()], $ids);
    }

    #[Test]
    public function it_lists_the_roles_a_user_holds_in_a_team(): void
    {
        $user = $this->createUser('roles-in-team@example.test');
        Authorization::withTeam(5, static function () use ($user): void {
            $user->assignRole(Role::EDITOR->value);
            $user->assignRole(Role::MEMBER->value);
        });

        $manager = resolve(AuthorizationManager::class);

        $this->assertEqualsCanonicalizing(['editor', 'member'], $manager->userRolesInTeam($user, 5));
        $this->assertSame([], $manager->userRolesInTeam($user, 9));
    }

    #[Test]
    public function reads_are_memoized_per_request_and_dropped_by_forget(): void
    {
        $user = $this->createUser('cached@example.test');
        Authorization::withTeam(null, static fn (): mixed => $user->assignRole(Role::ADMIN->value));

        $manager = resolve(AuthorizationManager::class);

        $this->assertTrue($manager->userHasGlobalRole($user, Role::ADMIN));

        // Remove the assignment behind the package's back: the memoized read stays true.
        DB::table('model_has_roles')->delete();
        $this->assertTrue($manager->userHasGlobalRole($user, Role::ADMIN));

        // After forgetting, the read reflects the database again.
        $manager->forgetUserRoles($user);
        $this->assertFalse($manager->userHasGlobalRole($user, Role::ADMIN));
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
