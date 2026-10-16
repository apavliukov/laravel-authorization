<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\Tenancy\EnsurePlatformArea;
use AlexPavliukov\Authorization\Tenancy\EnsureTenantSelected;
use AlexPavliukov\Authorization\Tenancy\SetCurrentTenant;
use AlexPavliukov\Authorization\Tenancy\TenantChoiceDropped;
use AlexPavliukov\Authorization\Tenancy\TenantResolver;
use AlexPavliukov\Authorization\Testing\InteractsWithAuthorization;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Post;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TenancyTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('tenancy')]
#[CoversClass(SetCurrentTenant::class)]
#[CoversClass(TenantResolver::class)]
#[CoversClass(EnsureTenantSelected::class)]
#[CoversClass(EnsurePlatformArea::class)]
final class TenantMiddlewareTest extends TenancyTestCase
{
    use InteractsWithAuthorization;

    private Organization $acme;

    private Organization $globex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::query()->create(['name' => 'Acme']);
        $this->globex = Organization::query()->create(['name' => 'Globex']);

        Route::middleware('web')->get('/tenant', static fn (): string => (string) resolve(CurrentTenant::class)->id());
        Route::middleware(['web', EnsureTenantSelected::class])->get('/needs-tenant', static fn (): string => 'ok');
        Route::middleware('web')->post('/choose/{id}', static function (Request $request, string $id): string {
            resolve(TenantResolver::class)->choose($request->user() ?? abort(401), $id);

            return (string) resolve(CurrentTenant::class)->id();
        });
        Route::middleware('web')->post('/clear', static function (): string {
            resolve(TenantResolver::class)->clearChoice();

            return (string) resolve(CurrentTenant::class)->id();
        });
        Route::middleware(['web', EnsurePlatformArea::class])->get('/platform', static fn (): int => Post::query()->count());
    }

    #[Test]
    public function a_sole_membership_is_the_tenant_without_a_session_choice(): void
    {
        $user = User::factory()->create();
        $this->assignRoleInTeam($user, Role::MEMBER, $this->acme->id);

        $this->actingAs($user)->get('/tenant')->assertOk()->assertContent((string) $this->acme->id);
    }

    #[Test]
    public function several_memberships_and_no_session_choice_resolve_to_no_tenant(): void
    {
        $user = User::factory()->create();
        $this->assignRoleInTeam($user, Role::MEMBER, $this->acme->id);
        $this->assignRoleInTeam($user, Role::EDITOR, $this->globex->id);

        $this->actingAs($user)->get('/tenant')->assertOk()->assertContent('');
        $this->actingAs($user)->get('/needs-tenant')->assertForbidden();
    }

    #[Test]
    public function a_global_role_alone_is_not_a_membership(): void
    {
        $admin = User::factory()->create();
        $this->assignRoleInTeam($admin, Role::ADMIN, null);

        $this->actingAs($admin)->get('/tenant')->assertOk()->assertContent('');
    }

    #[Test]
    public function the_session_choice_wins_when_the_user_is_a_member(): void
    {
        $user = User::factory()->create();
        $this->assignRoleInTeam($user, Role::MEMBER, $this->acme->id);
        $this->assignRoleInTeam($user, Role::MEMBER, $this->globex->id);

        $this->actingAs($user)
            ->withSession([TenantResolver::sessionKey() => $this->globex->id])
            ->get('/tenant')
            ->assertContent((string) $this->globex->id);
    }

    #[Test]
    public function a_session_tenant_the_user_does_not_belong_to_is_dropped_and_falls_back(): void
    {
        $user = User::factory()->create();
        $this->assignRoleInTeam($user, Role::MEMBER, $this->acme->id);

        $this->actingAs($user)
            ->withSession([TenantResolver::sessionKey() => $this->globex->id])
            ->get('/tenant')
            ->assertContent((string) $this->acme->id)
            ->assertSessionMissing(TenantResolver::sessionKey());
    }

    #[Test]
    public function dropping_a_stale_session_choice_dispatches_an_event(): void
    {
        Event::fake([TenantChoiceDropped::class]);
        $user = User::factory()->create();
        $this->assignRoleInTeam($user, Role::MEMBER, $this->acme->id);

        $this->actingAs($user)->get('/tenant');
        Event::assertNotDispatched(TenantChoiceDropped::class);

        $this->actingAs($user)->withSession([TenantResolver::sessionKey() => $this->globex->id])->get('/tenant');
        Event::assertDispatched(
            TenantChoiceDropped::class,
            fn (TenantChoiceDropped $event): bool => $event->user->getAuthIdentifier() === $user->id && $event->tenantId === $this->globex->id,
        );
    }

    #[Test]
    public function a_global_super_admin_may_enter_any_tenant_that_exists(): void
    {
        $admin = User::factory()->create();
        $this->assignRoleInTeam($admin, Role::ADMIN, null);

        $this->actingAs($admin)
            ->withSession([TenantResolver::sessionKey() => $this->acme->id])
            ->get('/tenant')
            ->assertContent((string) $this->acme->id);

        $this->actingAs($admin)
            ->withSession([TenantResolver::sessionKey() => 999])
            ->get('/tenant')
            ->assertContent('')
            ->assertSessionMissing(TenantResolver::sessionKey());
    }

    #[Test]
    public function a_membership_whose_tenant_was_deleted_never_activates_it(): void
    {
        $user = User::factory()->create();
        $this->assignRoleInTeam($user, Role::MEMBER, $this->acme->id);
        $this->acme->delete();

        $this->actingAs($user)->get('/tenant')->assertOk()->assertContent('');
        $this->actingAs($user)
            ->withSession([TenantResolver::sessionKey() => $this->acme->id])
            ->get('/tenant')
            ->assertContent('')
            ->assertSessionMissing(TenantResolver::sessionKey());
    }

    #[Test]
    public function a_tenant_outside_the_enterable_scope_is_dropped_from_the_session_even_for_a_super_admin(): void
    {
        config()->set('authorization.tenancy.enterable_scope', 'active');
        $this->acme->update(['is_active' => false]);
        $user = User::factory()->create();
        $this->assignRoleInTeam($user, Role::MEMBER, $this->acme->id);
        $admin = User::factory()->create();
        $this->assignRoleInTeam($admin, Role::ADMIN, null);

        foreach ([$user, $admin] as $actor) {
            $this->actingAs($actor)
                ->withSession([TenantResolver::sessionKey() => $this->acme->id])
                ->get('/tenant')
                ->assertContent('')
                ->assertSessionMissing(TenantResolver::sessionKey());
        }
    }

    #[Test]
    public function the_sole_enterable_tenant_is_picked_among_several_memberships(): void
    {
        $user = User::factory()->create();
        $this->assignRoleInTeam($user, Role::MEMBER, $this->acme->id);
        $this->assignRoleInTeam($user, Role::MEMBER, $this->globex->id);
        $this->acme->update(['is_active' => false]);

        $this->actingAs($user)->get('/tenant')->assertContent('');

        config()->set('authorization.tenancy.enterable_scope', 'active');

        $this->actingAs($user)->get('/tenant')->assertContent((string) $this->globex->id);
    }

    #[Test]
    public function choose_activates_the_tenant_now_and_remembers_it_for_the_next_request(): void
    {
        $user = User::factory()->create();
        $this->assignRoleInTeam($user, Role::MEMBER, $this->acme->id);
        $this->assignRoleInTeam($user, Role::MEMBER, $this->globex->id);

        $this->actingAs($user)->post('/choose/'.$this->globex->id)
            ->assertContent((string) $this->globex->id)
            ->assertSessionHas(TenantResolver::sessionKey(), $this->globex->id);
        $this->actingAs($user)->get('/tenant')->assertContent((string) $this->globex->id);

        $this->actingAs($user)->post('/clear')->assertContent('')->assertSessionMissing(TenantResolver::sessionKey());
        $this->actingAs($user)->get('/tenant')->assertContent('');
    }

    #[Test]
    public function choose_refuses_a_tenant_the_user_may_not_enter_and_keeps_the_current_choice(): void
    {
        config()->set('authorization.tenancy.enterable_scope', 'active');
        $user = User::factory()->create();
        $this->assignRoleInTeam($user, Role::MEMBER, $this->acme->id);
        $inactive = Organization::query()->create(['name' => 'Initech', 'is_active' => false]);
        $this->assignRoleInTeam($user, Role::MEMBER, $inactive->id);

        foreach ([$this->globex->id, $inactive->id, 999] as $id) {
            $this->actingAs($user)
                ->withSession([TenantResolver::sessionKey() => $this->acme->id])
                ->post('/choose/'.$id)
                ->assertForbidden()
                ->assertSessionHas(TenantResolver::sessionKey(), $this->acme->id);
        }
    }

    #[Test]
    public function ensure_tenant_selected_403s_without_a_tenant(): void
    {
        $this->actingAs(User::factory()->create())->get('/needs-tenant')->assertForbidden();
    }

    #[Test]
    public function ensure_tenant_selected_redirects_when_configured(): void
    {
        Route::get('/pick', static fn (): string => 'pick')->name('tenants.pick');
        config()->set('authorization.tenancy.missing_tenant_redirect', 'tenants.pick');

        $this->actingAs(User::factory()->create())->get('/needs-tenant')->assertRedirect('/pick');
    }

    #[Test]
    public function ensure_tenant_selected_passes_with_a_tenant(): void
    {
        $user = User::factory()->create();
        $this->assignRoleInTeam($user, Role::MEMBER, $this->acme->id);

        $this->actingAs($user)->get('/needs-tenant')->assertOk();
    }

    #[Test]
    public function platform_area_requires_the_platform_gate_and_runs_without_a_tenant(): void
    {
        resolve(CurrentTenant::class)->withoutTenant(fn (): Post => Post::query()->create(['organization_id' => $this->acme->id, 'title' => 'a']));
        $admin = User::factory()->create();
        $this->assignRoleInTeam($admin, Role::ADMIN, null);

        $this->actingAs($admin)->get('/platform')->assertOk()->assertSee('1');
        $this->actingAs(User::factory()->create())->get('/platform')->assertForbidden();
    }
}
