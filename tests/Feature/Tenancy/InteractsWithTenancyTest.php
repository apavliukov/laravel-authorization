<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\Testing\InteractsWithTenancy;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Post;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TenancyTestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('tenancy')]
#[CoversClass(InteractsWithTenancy::class)]
final class InteractsWithTenancyTest extends TenancyTestCase
{
    use InteractsWithTenancy;

    #[Test]
    public function acting_as_in_tenant_authenticates_activates_the_tenant_and_assigns_the_role(): void
    {
        $acme = Organization::query()->create(['name' => 'Acme']);
        $user = User::factory()->create();

        $this->actingAsInTenant($user, $acme, Role::MEMBER);

        $this->assertTrue($user->is(auth()->user()));
        $this->assertSame($acme->id, resolve(CurrentTenant::class)->id());
        $this->assertTrue($user->hasRole('member'));
    }

    #[Test]
    public function without_tenant_helper_runs_the_callback_bypassed(): void
    {
        $this->assertTrue($this->withoutTenant(static fn (): bool => resolve(CurrentTenant::class)->isBypassed()));
    }

    #[Test]
    public function assert_fails_closed_passes_for_a_tenant_owned_model(): void
    {
        $this->withoutTenant(static fn (): Post => Post::query()->create(['organization_id' => 1, 'title' => 'a']));

        $this->assertFailsClosed(Post::class);
    }

    #[Test]
    public function assert_fails_closed_fails_for_an_unscoped_model(): void
    {
        Organization::query()->create(['name' => 'Acme']);

        $this->expectException(AssertionFailedError::class);

        $this->assertFailsClosed(Organization::class);
    }
}
