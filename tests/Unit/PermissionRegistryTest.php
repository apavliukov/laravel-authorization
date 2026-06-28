<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Unit;

use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Enums\Ability;
use AlexPavliukov\Authorization\PermissionRegistry;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('policies')]
#[CoversClass(PermissionRegistry::class)]
final class PermissionRegistryTest extends TestCase
{
    private PermissionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = new AuthorizationManager;
        $manager->useRoleEnum(Role::class);
        $manager->authorizableModels([User::class]);

        $this->registry = new PermissionRegistry($manager);
    }

    /** @return array<string, array{Ability, string}> */
    public static function abilityToPermissionStringProvider(): array
    {
        return [
            'viewAny → view any users' => [Ability::VIEW_ANY, 'view any users'],
            'view → view users' => [Ability::VIEW, 'view users'],
            'create → create users' => [Ability::CREATE, 'create users'],
            'update → update users' => [Ability::UPDATE, 'update users'],
            'delete → delete users' => [Ability::DELETE, 'delete users'],
            'restore → restore users' => [Ability::RESTORE, 'restore users'],
            'forceDelete → force delete users' => [Ability::FORCE_DELETE, 'force delete users'],
        ];
    }

    #[Test]
    #[DataProvider('abilityToPermissionStringProvider')]
    public function it_converts_ability_and_model_class_to_permission_string(Ability $ability, string $expected): void
    {
        $this->assertSame($expected, $this->registry->nameFromAbility($ability, User::class));
    }

    #[Test]
    public function it_converts_ability_and_model_instance_to_permission_string(): void
    {
        $user = User::factory()->make();

        $this->assertSame('view users', $this->registry->nameFromAbility(Ability::VIEW, $user));
    }

    #[Test]
    public function all_permissions_reflects_the_configured_models(): void
    {
        $permissions = $this->registry->allPermissions();

        foreach (Ability::cases() as $ability) {
            $this->assertContains(
                $this->registry->nameFromAbility($ability, User::class),
                $permissions,
            );
        }
    }
}
