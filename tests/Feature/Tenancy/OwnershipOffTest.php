<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\Database\OwnershipRepair;
use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\Tenancy\TenantMembership;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TenancyTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/** A 1.2 app: `owner_role` unset ⇒ the ownership feature is inert. */
#[Group('tenancy')]
final class OwnershipOffTest extends TenancyTestCase
{
    #[Test]
    public function guards_listener_and_repair_are_inert(): void
    {
        $user = User::factory()->create();
        $acme = Organization::query()->create(['name' => 'Acme', 'owner_id' => $user->id]);
        $membership = resolve(TenantMembership::class);

        $membership->add($user, $acme, Role::OWNER);
        $membership->changeRole($user, $acme, Role::MEMBER);
        $membership->remove($user, $acme);
        $this->assertFalse($membership->isOwner($user, $acme));
        $this->assertIsString(resolve(OwnershipRepair::class)->apply());

        $user->delete();
        $this->assertNull(User::query()->find($user->id));

        $this->expectException(LogicException::class);
        $acme->owner();
    }

    #[Test]
    public function owner_role_without_owner_key_is_a_config_error(): void
    {
        config(['authorization.tenancy.owner_role' => Role::OWNER, 'authorization.tenancy.owner_key' => null]);

        $this->expectException(LogicException::class);

        resolve(CurrentTenant::class)->assertValidConfig();
    }

    #[Test]
    public function fallback_to_owned_without_ownership_is_a_config_error(): void
    {
        config(['authorization.tenancy.fallback_to_owned' => true]);

        $this->expectException(LogicException::class);

        resolve(CurrentTenant::class)->assertValidConfig();
    }

    #[Test]
    public function an_unknown_membership_policy_is_a_config_error(): void
    {
        config(['authorization.tenancy.memberships' => 'one']);

        $this->expectException(LogicException::class);

        resolve(CurrentTenant::class)->assertValidConfig();
    }
}
