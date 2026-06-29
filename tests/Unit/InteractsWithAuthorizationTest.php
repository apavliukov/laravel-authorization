<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Unit;

use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Testing\InteractsWithAuthorization;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TeamsTestCase;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role as SpatieRole;

#[Group('policies')]
#[CoversTrait(InteractsWithAuthorization::class)]
final class InteractsWithAuthorizationTest extends TeamsTestCase
{
    use InteractsWithAuthorization;

    #[Test]
    public function role_model_id_resolves_the_roles_table_id(): void
    {
        $id = SpatieRole::query()
            ->where('name', Role::ADMIN->value)
            ->where('guard_name', 'web')
            ->value('id');
        $expected = is_numeric($id) ? (int) $id : 0;

        $this->assertSame($expected, $this->roleModelId(Role::ADMIN));
    }

    #[Test]
    public function assign_role_in_team_assigns_within_the_given_team(): void
    {
        $user = $this->createUser('in-team@example.test');

        $this->assignRoleInTeam($user, Role::EDITOR, 5);

        $manager = resolve(AuthorizationManager::class);

        $this->assertTrue($manager->userHasRoleInTeam($user, Role::EDITOR, 5));
        $this->assertFalse($manager->userHasGlobalRole($user, Role::EDITOR));
    }

    #[Test]
    public function assign_role_in_team_assigns_globally_when_team_is_null(): void
    {
        $user = $this->createUser('global@example.test');

        $this->assignRoleInTeam($user, Role::ADMIN, null);

        $this->assertTrue(resolve(AuthorizationManager::class)->userHasGlobalRole($user, Role::ADMIN));
    }

    #[Test]
    public function with_permissions_team_restores_the_previous_team(): void
    {
        setPermissionsTeamId(2);

        $teamInside = $this->withPermissionsTeam(8, static fn (): int|string|null => getPermissionsTeamId());

        $this->assertSame(8, $teamInside);
        $this->assertSame(2, getPermissionsTeamId());

        $this->resetPermissionsTeam();

        $this->assertNull(getPermissionsTeamId());
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
