<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Unit;

use AlexPavliukov\Authorization\Authorization;
use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TeamsTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;

#[Group('policies')]
#[CoversClass(AuthorizationManager::class)]
final class UserCanTest extends TeamsTestCase
{
    #[Test]
    public function it_matches_user_can_for_a_granted_and_a_denied_permission(): void
    {
        setPermissionsTeamId(5);
        Permission::findOrCreate('view users', 'web');
        Permission::findOrCreate('update users', 'web');

        $user = $this->createUser('parity@example.test');
        $user->givePermissionTo('view users');

        $manager = resolve(AuthorizationManager::class);

        $this->assertSame($user->can('view users'), $manager->userCan($user, 'view users'));
        $this->assertSame($user->can('update users'), $manager->userCan($user, 'update users'));
        $this->assertTrue($manager->userCan($user, 'view users'));
        $this->assertFalse($manager->userCan($user, 'update users'));
    }

    #[Test]
    public function a_verdict_recomputes_when_the_active_team_switches(): void
    {
        Permission::findOrCreate('view users', 'web');

        $user = $this->createUser('team-switch@example.test');
        Authorization::withTeam(5, static fn (): mixed => $user->givePermissionTo('view users'));

        $manager = resolve(AuthorizationManager::class);

        setPermissionsTeamId(5);
        $this->assertTrue($manager->userCan($user, 'view users'));

        $freshUnderOtherTeam = User::query()->where('id', $user->getKey())->firstOrFail();
        setPermissionsTeamId(9);
        $this->assertFalse($manager->userCan($freshUnderOtherTeam, 'view users'));

        setPermissionsTeamId(null);
    }

    #[Test]
    public function verdicts_do_not_leak_between_users_in_the_same_request(): void
    {
        setPermissionsTeamId(5);
        Permission::findOrCreate('view users', 'web');

        $granted = $this->createUser('granted@example.test');
        $granted->givePermissionTo('view users');
        $other = $this->createUser('other@example.test');

        $manager = resolve(AuthorizationManager::class);

        $this->assertTrue($manager->userCan($granted, 'view users'));
        $this->assertFalse($manager->userCan($other, 'view users'));
    }

    #[Test]
    public function it_wraps_the_full_gate_pipeline_so_the_super_admin_bypass_grants_the_permission(): void
    {
        $admin = $this->createUser('super@example.test');
        Authorization::withTeam(null, static fn (): mixed => $admin->assignRole(Role::ADMIN->value));

        $manager = resolve(AuthorizationManager::class);

        $this->assertTrue($manager->userCan($admin, 'view users'));
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
