<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature;

use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Tests\Fixtures\SystemAbility;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('policies')]
#[CoversClass(AuthorizationManager::class)]
final class SystemAbilitiesTest extends TestCase
{
    #[Test]
    public function a_system_ability_denies_a_regular_user_by_default(): void
    {
        $member = User::factory()->member()->create();

        $this->assertFalse($member->can(SystemAbility::ACCESS_PLATFORM_ADMIN->value));
    }

    #[Test]
    public function a_super_admin_is_granted_the_system_ability_through_the_bypass(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertTrue($admin->can(SystemAbility::ACCESS_PLATFORM_ADMIN->value));
    }
}
