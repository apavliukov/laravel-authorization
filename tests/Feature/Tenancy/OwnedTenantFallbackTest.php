<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\Tenancy\TenantMembership;
use AlexPavliukov\Authorization\Tenancy\TenantResolver;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\OwnershipTestCase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('tenancy')]
#[CoversClass(TenantResolver::class)]
final class OwnedTenantFallbackTest extends OwnershipTestCase
{
    private User $owner;

    private Organization $acme;

    private Organization $globex;

    protected function setUp(): void
    {
        parent::setUp();

        $membership = resolve(TenantMembership::class);
        $this->owner = User::factory()->create();
        $this->acme = Organization::query()->create(['name' => 'Acme', 'owner_id' => $this->owner->id]);
        $membership->transferOwnership($this->acme, $this->owner);
        $this->globex = Organization::query()->create(['name' => 'Globex', 'owner_id' => User::factory()->create()->id]);
        $membership->add($this->owner, $this->globex, Role::MEMBER);

        Route::middleware('web')->get('/tenant', static fn (): string => (string) resolve(CurrentTenant::class)->id());
    }

    #[Test]
    public function several_memberships_pick_the_owned_tenant_only_when_opted_in(): void
    {
        $this->actingAs($this->owner)->get('/tenant')->assertContent('');

        config()->set('authorization.tenancy.fallback_to_owned', true);

        $this->actingAs($this->owner)->get('/tenant')->assertContent((string) $this->acme->id);
    }

    #[Test]
    public function an_owned_tenant_outside_the_enterable_scope_is_not_picked(): void
    {
        config()->set('authorization.tenancy.fallback_to_owned', true);
        config()->set('authorization.tenancy.enterable_scope', 'active');
        $initech = Organization::query()->create(['name' => 'Initech', 'owner_id' => User::factory()->create()->id]);
        resolve(TenantMembership::class)->add($this->owner, $initech, Role::MEMBER);
        $this->acme->update(['is_active' => false]);

        $this->actingAs($this->owner)->get('/tenant')->assertContent('');
    }

    #[Test]
    public function the_session_choice_still_wins_over_the_owned_tenant(): void
    {
        config()->set('authorization.tenancy.fallback_to_owned', true);

        $this->actingAs($this->owner)
            ->withSession([TenantResolver::sessionKey() => $this->globex->id])
            ->get('/tenant')
            ->assertContent((string) $this->globex->id);
    }
}
