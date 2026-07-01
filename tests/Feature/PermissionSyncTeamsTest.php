<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature;

use AlexPavliukov\Authorization\Database\PermissionSync;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\TeamsTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

#[Group('policies')]
#[CoversClass(PermissionSync::class)]
final class PermissionSyncTeamsTest extends TeamsTestCase
{
    #[Test]
    public function an_empty_role_stays_deny_all_with_teams_enabled(): void
    {
        Permission::findOrCreate('view users', 'web');
        $sync = resolve(PermissionSync::class);

        setPermissionsTeamId(5);
        $sync->apply();

        // role_has_permissions is not team-scoped, so the detach applies to the
        // role regardless of team context.
        SpatieRole::findByName(Role::MEMBER->value, 'web')->givePermissionTo('view users');
        $sync->apply();

        $this->assertFalse(SpatieRole::findByName(Role::MEMBER->value, 'web')->hasPermissionTo('view users'));
    }
}
