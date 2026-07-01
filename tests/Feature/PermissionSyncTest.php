<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature;

use AlexPavliukov\Authorization\Database\AuthorizationSeeder;
use AlexPavliukov\Authorization\Database\PermissionSync;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

#[Group('policies')]
#[CoversClass(PermissionSync::class)]
#[CoversClass(AuthorizationSeeder::class)]
final class PermissionSyncTest extends TestCase
{
    #[Test]
    public function it_creates_a_permission_for_every_user_ability(): void
    {
        resolve(PermissionSync::class)->permissions();

        $this->assertDatabaseHas('permissions', ['name' => 'view any users', 'guard_name' => 'web']);
        $this->assertDatabaseHas('permissions', ['name' => 'force delete users', 'guard_name' => 'web']);
        $this->assertSame(7, Permission::query()->count());
    }

    #[Test]
    public function it_creates_every_role_and_grants_their_permissions(): void
    {
        resolve(PermissionSync::class)->permissions();
        resolve(PermissionSync::class)->roles();

        foreach (Role::cases() as $role) {
            $this->assertDatabaseHas('roles', ['name' => $role->value, 'guard_name' => 'web']);
        }

        $editor = SpatieRole::findByName(Role::EDITOR->value, 'web');

        $this->assertTrue($editor->hasPermissionTo('view users'));
        $this->assertTrue($editor->hasPermissionTo('update users'));
    }

    #[Test]
    public function the_seeder_syncs_permissions_then_roles(): void
    {
        resolve(AuthorizationSeeder::class)->run();

        $this->assertSame(7, Permission::query()->count());
        $this->assertSame(count(Role::cases()), SpatieRole::query()->count());
    }

    #[Test]
    public function plan_lists_creations_and_role_grants_on_a_clean_database(): void
    {
        $plan = resolve(PermissionSync::class)->plan();

        $this->assertContains('view any users', $plan['permissions']['create']);
        $this->assertCount(7, $plan['permissions']['create']);
        $this->assertSame([], $plan['permissions']['remove']);
        $this->assertEqualsCanonicalizing(['view users', 'update users'], $plan['roles']['editor']['grant']);
        $this->assertSame([], $plan['roles']['admin']['grant']);
    }

    #[Test]
    public function plan_reports_undeclared_permissions_as_removals(): void
    {
        Permission::query()->create(['name' => 'legacy ability', 'guard_name' => 'web']);

        $plan = resolve(PermissionSync::class)->plan();

        $this->assertContains('legacy ability', $plan['permissions']['remove']);
    }

    #[Test]
    public function apply_without_prune_keeps_undeclared_permissions(): void
    {
        Permission::query()->create(['name' => 'legacy ability', 'guard_name' => 'web']);

        resolve(PermissionSync::class)->apply();

        $this->assertDatabaseHas('permissions', ['name' => 'legacy ability']);
        $this->assertDatabaseHas('permissions', ['name' => 'view any users']);
    }

    #[Test]
    public function apply_with_prune_removes_undeclared_permissions(): void
    {
        Permission::query()->create(['name' => 'legacy ability', 'guard_name' => 'web']);

        resolve(PermissionSync::class)->apply(true);

        $this->assertDatabaseMissing('permissions', ['name' => 'legacy ability']);
        $this->assertDatabaseHas('permissions', ['name' => 'view any users']);
    }

    #[Test]
    public function an_empty_permission_role_is_enforced_as_deny_all(): void
    {
        Permission::findOrCreate('view users', 'web');
        $sync = resolve(PermissionSync::class);
        $sync->apply();

        // MEMBER declares [] — a stray permission on it must be revoked on re-seed.
        SpatieRole::findByName(Role::MEMBER->value, 'web')->givePermissionTo('view users');
        $this->assertTrue(SpatieRole::findByName(Role::MEMBER->value, 'web')->hasPermissionTo('view users'));

        $sync->apply();

        $this->assertFalse(SpatieRole::findByName(Role::MEMBER->value, 'web')->hasPermissionTo('view users'));
    }

    #[Test]
    public function plan_and_apply_agree_for_an_empty_role(): void
    {
        Permission::findOrCreate('view users', 'web');
        $sync = resolve(PermissionSync::class);
        $sync->apply();

        SpatieRole::findByName(Role::MEMBER->value, 'web')->givePermissionTo('view users');

        $plan = $sync->plan();
        $this->assertSame(['view users'], $plan['roles']['member']['revoke']);
        $this->assertSame([], $plan['roles']['member']['grant']);

        $sync->apply();

        $this->assertFalse(SpatieRole::findByName(Role::MEMBER->value, 'web')->hasPermissionTo('view users'));
    }

    #[Test]
    public function a_non_empty_role_syncs_to_exactly_its_declaration(): void
    {
        foreach (['view users', 'update users', 'delete users'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $sync = resolve(PermissionSync::class);
        $sync->apply();

        // EDITOR declares [view users, update users]: revoke a stray, restore a removed one.
        $editor = SpatieRole::findByName(Role::EDITOR->value, 'web');
        $editor->givePermissionTo('delete users');
        $editor->revokePermissionTo('view users');

        $sync->apply();

        $editor = SpatieRole::findByName(Role::EDITOR->value, 'web');
        $this->assertTrue($editor->hasPermissionTo('view users'));
        $this->assertTrue($editor->hasPermissionTo('update users'));
        $this->assertFalse($editor->hasPermissionTo('delete users'));
    }
}
