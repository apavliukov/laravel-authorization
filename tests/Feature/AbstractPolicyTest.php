<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature;

use AlexPavliukov\Authorization\AbstractPolicy;
use AlexPavliukov\Authorization\Tests\Fixtures\ScopedUserPolicy;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\Fixtures\UserPolicy;
use AlexPavliukov\Authorization\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;

#[Group('policies')]
#[CoversClass(AbstractPolicy::class)]
final class AbstractPolicyTest extends TestCase
{
    #[Test]
    public function it_denies_when_the_user_lacks_the_permission(): void
    {
        $member = User::factory()->member()->create();
        $target = User::factory()->create();

        $this->assertFalse(resolve(UserPolicy::class)->view($member, $target));
    }

    #[Test]
    public function it_grants_when_the_user_holds_the_matching_permission(): void
    {
        Permission::findOrCreate('view users', 'web');

        $member = User::factory()->member()->create();
        $member->givePermissionTo('view users');
        $target = User::factory()->create();

        $this->assertTrue(resolve(UserPolicy::class)->view($member, $target));
    }

    #[Test]
    public function a_super_admin_is_granted_through_the_bypass_without_an_explicit_permission(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $this->assertTrue(resolve(UserPolicy::class)->view($admin, $target));
    }

    #[Test]
    public function owns_model_hook_fences_a_model_bound_check_despite_the_permission(): void
    {
        Permission::findOrCreate('view users', 'web');

        $member = User::factory()->member()->create();
        $member->givePermissionTo('view users');
        $other = User::factory()->create();

        $policy = resolve(ScopedUserPolicy::class);

        $this->assertTrue($policy->view($member, $member));
        $this->assertFalse($policy->view($member, $other));
    }

    #[Test]
    public function owns_model_hook_does_not_apply_to_model_less_checks(): void
    {
        Permission::findOrCreate('view any users', 'web');

        $member = User::factory()->member()->create();
        $member->givePermissionTo('view any users');

        $this->assertTrue(resolve(ScopedUserPolicy::class)->viewAny($member));
    }
}
