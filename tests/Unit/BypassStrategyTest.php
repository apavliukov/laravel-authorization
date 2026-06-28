<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Unit;

use AlexPavliukov\Authorization\Authorization;
use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Enums\Ability;
use AlexPavliukov\Authorization\Enums\SystemAbility;
use AlexPavliukov\Authorization\Support\BypassGate;
use AlexPavliukov\Authorization\Support\NoBypass;
use AlexPavliukov\Authorization\Support\RoleBypass;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('policies')]
#[CoversClass(RoleBypass::class)]
#[CoversClass(NoBypass::class)]
#[CoversClass(BypassGate::class)]
final class BypassStrategyTest extends TestCase
{
    #[Test]
    public function super_admin_bypasses_any_ability(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('any.unregistered.ability'));
    }

    #[Test]
    public function non_super_admin_does_not_bypass(): void
    {
        $member = User::factory()->member()->create();

        $this->assertFalse(Gate::forUser($member)->allows('any.unregistered.ability'));
    }

    #[Test]
    public function platform_admin_access_is_granted_to_super_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create();

        $this->assertTrue(Gate::forUser($admin)->allows(SystemAbility::ACCESS_PLATFORM_ADMIN->value));
        $this->assertFalse(Gate::forUser($member)->allows(SystemAbility::ACCESS_PLATFORM_ADMIN->value));
    }

    #[Test]
    public function role_bypass_routes_protected_abilities_to_policies(): void
    {
        $admin = User::factory()->admin()->create();
        $strategy = new RoleBypass(resolve(AuthorizationManager::class), [Ability::UPDATE]);

        $this->assertFalse($strategy->shouldBypass($admin, Ability::UPDATE->value));
        $this->assertTrue($strategy->shouldBypass($admin, Ability::DELETE->value));
    }

    #[Test]
    public function no_bypass_never_bypasses_even_for_super_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertFalse((new NoBypass)->shouldBypass($admin, 'any.ability'));

        Authorization::bypassUsing(NoBypass::class);

        $this->assertFalse(Gate::forUser($admin)->allows('any.unregistered.ability'));
    }
}
