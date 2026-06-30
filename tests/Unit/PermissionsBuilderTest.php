<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Unit;

use AlexPavliukov\Authorization\Enums\Ability;
use AlexPavliukov\Authorization\Support\Permissions;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('policies')]
#[CoversClass(Permissions::class)]
final class PermissionsBuilderTest extends TestCase
{
    #[Test]
    public function it_builds_named_permissions_for_specific_abilities(): void
    {
        $names = Permissions::make()
            ->for(User::class)->only(Ability::VIEW, Ability::UPDATE)
            ->all();

        $this->assertSame(['view users', 'update users'], $names);
    }

    #[Test]
    public function for_all_expands_every_exposed_ability_of_a_model(): void
    {
        $names = Permissions::make()->forAll(User::class)->all();

        $this->assertCount(7, $names);
        $this->assertContains('view any users', $names);
        $this->assertContains('force delete users', $names);
    }

    #[Test]
    public function it_combines_blocks_and_dedupes(): void
    {
        $names = Permissions::make()
            ->for(User::class)->only(Ability::VIEW)
            ->forAll(User::class)
            ->all();

        $this->assertCount(7, $names);
    }

    #[Test]
    public function only_without_for_throws(): void
    {
        $this->expectException(LogicException::class);

        Permissions::make()->only(Ability::VIEW);
    }
}
