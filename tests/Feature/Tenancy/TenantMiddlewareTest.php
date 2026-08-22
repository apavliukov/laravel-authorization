<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\Tenancy\EnsurePlatformArea;
use AlexPavliukov\Authorization\Tenancy\EnsureTenantSelected;
use AlexPavliukov\Authorization\Tenancy\FromSessionResolver;
use AlexPavliukov\Authorization\Tenancy\FromUserResolver;
use AlexPavliukov\Authorization\Tenancy\SetCurrentTenant;
use AlexPavliukov\Authorization\Testing\InteractsWithAuthorization;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Post;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TenancyTestCase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('tenancy')]
#[CoversClass(SetCurrentTenant::class)]
#[CoversClass(FromUserResolver::class)]
#[CoversClass(FromSessionResolver::class)]
#[CoversClass(EnsureTenantSelected::class)]
#[CoversClass(EnsurePlatformArea::class)]
final class TenantMiddlewareTest extends TenancyTestCase
{
    use InteractsWithAuthorization;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::query()->create(['name' => 'Acme']);

        Route::middleware('web')->get('/tenant', static fn (): string => (string) resolve(CurrentTenant::class)->id());
        Route::middleware(['web', EnsureTenantSelected::class])->get('/needs-tenant', static fn (): string => 'ok');
        Route::middleware(['web', EnsurePlatformArea::class])->get('/platform', static fn (): int => Post::query()->count());
    }

    #[Test]
    public function user_mode_reads_the_tenant_from_the_user_column(): void
    {
        $user = User::factory()->create(['organization_id' => $this->acme->id]);

        $this->actingAs($user)->get('/tenant')->assertSee((string) $this->acme->id);
    }

    #[Test]
    public function session_mode_honours_the_session_tenant_when_the_user_is_a_member(): void
    {
        config()->set('authorization.tenancy.resolver', 'session');
        $user = User::factory()->create();
        $this->assignRoleInTeam($user, Role::MEMBER, $this->acme->id);

        $this->actingAs($user)
            ->withSession([FromSessionResolver::sessionKey() => $this->acme->id])
            ->get('/tenant')
            ->assertSee((string) $this->acme->id);
    }

    #[Test]
    public function session_mode_drops_a_session_tenant_the_user_does_not_belong_to(): void
    {
        config()->set('authorization.tenancy.resolver', 'session');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession([FromSessionResolver::sessionKey() => $this->acme->id])
            ->get('/tenant')
            ->assertSee('')
            ->assertSessionMissing(FromSessionResolver::sessionKey());
    }

    #[Test]
    public function session_mode_lets_a_global_super_admin_enter_any_tenant(): void
    {
        config()->set('authorization.tenancy.resolver', 'session');
        $admin = User::factory()->create();
        $this->assignRoleInTeam($admin, Role::ADMIN, null);

        $this->actingAs($admin)
            ->withSession([FromSessionResolver::sessionKey() => $this->acme->id])
            ->get('/tenant')
            ->assertSee((string) $this->acme->id);
    }

    #[Test]
    public function session_mode_drops_a_stale_tenant_id_even_for_a_super_admin(): void
    {
        config()->set('authorization.tenancy.resolver', 'session');
        $admin = User::factory()->create();
        $this->assignRoleInTeam($admin, Role::ADMIN, null);

        $this->actingAs($admin)
            ->withSession([FromSessionResolver::sessionKey() => 999])
            ->get('/tenant')
            ->assertSee('')
            ->assertSessionMissing(FromSessionResolver::sessionKey());
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
        $user = User::factory()->create(['organization_id' => $this->acme->id]);

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
