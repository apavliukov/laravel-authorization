<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature;

use AlexPavliukov\Authorization\Authorization;
use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TeamsTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('policies')]
#[CoversClass(AuthorizationManager::class)]
final class PrimaryRoleTeamsTest extends TeamsTestCase
{
    #[Test]
    public function global_and_team_roles_rank_in_one_list(): void
    {
        $user = User::factory()->create();

        setPermissionsTeamId(null);
        $user->assignRole(Role::MEMBER->value);

        setPermissionsTeamId(5);
        $user->unsetRelation('roles')->assignRole(Role::EDITOR->value);

        setPermissionsTeamId(null);

        // The global MEMBER ranks below EDITOR, held only in team 5.
        $this->assertSame(Role::EDITOR, Authorization::primaryRole($user));
    }
}
