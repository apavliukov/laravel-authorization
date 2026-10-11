<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\Database\AuthorizationSeeder;
use AlexPavliukov\Authorization\Database\OwnershipRepair;
use AlexPavliukov\Authorization\Support\ModelHasRolesQuery;
use AlexPavliukov\Authorization\Tenancy\Concerns\HasMembers;
use AlexPavliukov\Authorization\Tenancy\MembershipPolicyException;
use AlexPavliukov\Authorization\Tenancy\OwnershipException;
use AlexPavliukov\Authorization\Tenancy\TenantMembership;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\OwnershipTestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role as SpatieRole;

#[Group('tenancy')]
#[CoversClass(TenantMembership::class)]
#[CoversClass(OwnershipRepair::class)]
#[CoversClass(ModelHasRolesQuery::class)]
#[CoversClass(HasMembers::class)]
final class OwnershipTest extends OwnershipTestCase
{
    private TenantMembership $membership;

    private User $owner;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->membership = resolve(TenantMembership::class);
        $this->owner = User::factory()->create();
        $this->acme = Organization::query()->create(['name' => 'Acme', 'owner_id' => $this->owner->id]);
        $this->membership->transferOwnership($this->acme, $this->owner);   // creation path writes the role row
    }

    #[Test]
    public function transfer_moves_the_column_and_both_role_rows(): void
    {
        $next = User::factory()->create();
        $this->membership->add($next, $this->acme, Role::MEMBER);

        $this->membership->transferOwnership($this->acme, $next, Role::EDITOR);

        $this->assertSame($next->id, $this->acme->fresh()?->owner_id);
        $this->assertSame([Role::OWNER->value], $this->rolesIn($next));
        $this->assertSame([Role::EDITOR->value], $this->rolesIn($this->owner));
        $this->assertSame('editor', $this->membership->roleFor($this->owner, $this->acme));   // memo flushed
    }

    #[Test]
    public function transfer_without_demotion_drops_the_previous_owner(): void
    {
        $next = User::factory()->create();

        $this->membership->transferOwnership($this->acme, $next);

        $this->assertSame([], $this->rolesIn($this->owner));
        $this->assertTrue($this->membership->isOwner($next, $this->acme->id));
    }

    #[Test]
    public function transfer_to_the_current_owner_changes_nothing(): void
    {
        $this->membership->transferOwnership($this->acme, $this->owner);

        $this->assertSame([Role::OWNER->value], $this->rolesIn($this->owner));
        $this->assertSame(1, DB::table('model_has_roles')->where('team_id', $this->acme->id)->count());
    }

    #[Test]
    public function a_failed_role_write_rolls_the_column_back(): void
    {
        $next = User::factory()->create();
        SpatieRole::findByName(Role::OWNER->value, 'web')->delete();

        $this->assertThrows(fn () => $this->membership->transferOwnership($this->acme, $next), RoleDoesNotExist::class);

        $this->assertSame($this->owner->id, $this->acme->fresh()?->owner_id);
        $this->assertSame($this->owner->id, $this->acme->owner_id);
    }

    #[Test]
    public function demoting_to_the_owner_role_is_refused(): void
    {
        $this->expectException(OwnershipException::class);

        $this->membership->transferOwnership($this->acme, User::factory()->create(), Role::OWNER);
    }

    #[Test]
    public function the_owners_membership_cannot_be_edited(): void
    {
        foreach ([
            fn () => $this->membership->changeRole($this->owner, $this->acme, Role::EDITOR),
            fn () => $this->membership->remove($this->owner, $this->acme->id),
            fn () => $this->membership->add($this->owner, $this->acme, Role::EDITOR),
        ] as $write) {
            $this->assertThrows($write, OwnershipException::class);
        }

        $this->membership->add($this->owner, $this->acme, Role::OWNER);   // already true: allowed
        $this->assertSame([Role::OWNER->value], $this->rolesIn($this->owner));
    }

    #[Test]
    public function the_owner_role_cannot_be_handed_out_through_membership_writes(): void
    {
        $member = User::factory()->create();
        $this->membership->add($member, $this->acme, Role::MEMBER);

        $this->assertThrows(fn () => $this->membership->add($member, $this->acme, Role::OWNER), OwnershipException::class);
        $this->assertThrows(fn () => $this->membership->changeRole($member, $this->acme, Role::OWNER), OwnershipException::class);

        $this->membership->changeRole($member, $this->acme, Role::EDITOR);
        $this->membership->remove($member, $this->acme);
        $this->assertFalse($this->membership->isMember($member, $this->acme));
    }

    #[Test]
    public function an_owner_cannot_be_deleted_but_a_member_can(): void
    {
        $member = User::factory()->create();
        $this->membership->add($member, $this->acme, Role::MEMBER);

        $this->assertThrows(fn () => $this->owner->delete(), OwnershipException::class);
        $this->assertNotNull(User::query()->find($this->owner->id));

        $member->delete();
        $this->assertNull(User::query()->find($member->id));
    }

    #[Test]
    public function owning_only_an_archived_tenant_still_blocks_deletion(): void
    {
        $this->acme->delete();

        $this->assertThrows(fn () => $this->owner->delete(), OwnershipException::class);
    }

    #[Test]
    public function owner_reads_go_through_the_column(): void
    {
        $this->assertSame($this->owner->id, $this->membership->ownerOf($this->acme)?->getAuthIdentifier());
        $this->assertTrue($this->owner->is($this->acme->owner()->first()));
        $this->assertTrue($this->membership->isOwner($this->owner, $this->acme->id));
        $this->assertFalse($this->membership->isOwner(User::factory()->create(), $this->acme));
    }

    #[Test]
    public function single_membership_refuses_transferring_to_a_member_of_another_tenant(): void
    {
        config(['authorization.tenancy.memberships' => 'single']);
        $globex = Organization::query()->create(['name' => 'Globex']);
        $other = User::factory()->create();
        $this->membership->add($other, $globex, Role::MEMBER);

        $this->expectException(MembershipPolicyException::class);

        $this->membership->transferOwnership($this->acme, $other);
    }

    #[Test]
    public function repair_restores_the_owner_row_and_removes_strays(): void
    {
        $intruder = User::factory()->create();
        $archived = Organization::query()->create(['name' => 'Old', 'owner_id' => $intruder->id]);
        $archived->delete();
        Organization::query()->create(['name' => 'Ownerless']);   // NULL owner: left alone
        DB::table('model_has_roles')->delete();
        $this->membership->assignGlobal($intruder, Role::OWNER);   // global row: not a stray
        $this->membership->add($intruder, $this->acme, Role::MEMBER);
        DB::table('model_has_roles')->insert([
            'role_id' => SpatieRole::findByName(Role::OWNER->value, 'web')->id,
            'model_type' => $intruder->getMorphClass(),
            'model_id' => $intruder->id,
            'team_id' => $this->acme->id,
        ]);

        $this->assertSame(['created' => 2, 'removed' => 1], resolve(OwnershipRepair::class)->apply());

        $this->assertSame([Role::OWNER->value], $this->rolesIn($this->owner));
        $this->assertSame([Role::MEMBER->value], $this->rolesIn($intruder));
        $this->assertSame([Role::OWNER->value], resolve(ModelHasRolesQuery::class)->rolesInTeam($intruder, $archived->id));
        $this->assertSame(['created' => 0, 'removed' => 0], resolve(OwnershipRepair::class)->apply());
    }

    #[Test]
    public function the_seeder_runs_the_repair(): void
    {
        DB::table('model_has_roles')->delete();

        $this->seed(AuthorizationSeeder::class);

        $this->assertSame([Role::OWNER->value], $this->rolesIn($this->owner));
    }

    /** @return list<string> */
    private function rolesIn(User $user): array
    {
        return resolve(ModelHasRolesQuery::class)->rolesInTeam($user, $this->acme->id);
    }
}
