<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature;

use AlexPavliukov\Authorization\Authorization;
use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('policies')]
#[CoversClass(AuthorizationManager::class)]
final class PrimaryRoleTest extends TestCase
{
    #[Test]
    public function it_returns_the_highest_priority_role_by_declaration_order(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::EDITOR->value);
        $user->assignRole(Role::MEMBER->value);

        // Role cases are declared ADMIN, MEMBER, EDITOR — MEMBER outranks EDITOR.
        $this->assertSame(Role::MEMBER, Authorization::primaryRole($user));
    }

    #[Test]
    public function a_super_admin_outranks_a_lower_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::EDITOR->value);
        $user->assignRole(Role::ADMIN->value);

        $this->assertSame(Role::ADMIN, Authorization::primaryRole($user));
    }

    #[Test]
    public function it_returns_null_when_the_user_holds_no_role(): void
    {
        $this->assertNull(Authorization::primaryRole(User::factory()->create()));
    }
}
