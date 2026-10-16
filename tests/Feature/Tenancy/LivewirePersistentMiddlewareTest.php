<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\AuthorizationServiceProvider;
use AlexPavliukov\Authorization\Tenancy\CurrentArea;
use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\Tenancy\EnsurePlatformArea;
use AlexPavliukov\Authorization\Tenancy\EnsureTenantSelected;
use AlexPavliukov\Authorization\Tenancy\TenantMembership;
use AlexPavliukov\Authorization\Tenancy\TenantResolver;
use AlexPavliukov\Authorization\Testing\InteractsWithAuthorization;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\AreaProbe;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Post;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\PostCounter;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\PostTitle;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TenancyTestCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Livewire replays only persistent middleware on /livewire/update, through a
 * pipeline whose $next returns at once — so the fence must hold for the whole
 * update request, not just around $next. Exercised over real HTTP.
 */
#[Group('tenancy')]
#[CoversClass(AuthorizationServiceProvider::class)]
#[CoversClass(EnsurePlatformArea::class)]
#[CoversClass(EnsureTenantSelected::class)]
#[CoversClass(CurrentArea::class)]
final class LivewirePersistentMiddlewareTest extends TenancyTestCase
{
    use InteractsWithAuthorization;

    private Organization $acme;

    private Organization $globex;

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
        Livewire::component('post-counter', PostCounter::class);
        Livewire::component('post-title', PostTitle::class);
        Livewire::component('area-probe', AreaProbe::class);

        $this->acme = Organization::query()->create(['name' => 'Acme']);
        $this->globex = Organization::query()->create(['name' => 'Globex']);
        resolve(CurrentTenant::class)->withoutTenant(function (): void {
            Post::query()->create(['organization_id' => $this->acme->id, 'title' => 'a']);
            Post::query()->create(['organization_id' => $this->globex->id, 'title' => 'g']);
        });

        $page = static fn (): string => Blade::render('<html><body>@livewire(\'post-counter\')</body></html>');
        Route::middleware(['web', EnsurePlatformArea::class])->get('/platform/posts', $page)->name('admin.platform.posts');
        Route::middleware(['web', EnsureTenantSelected::class])->get('/organization/posts', $page)->name('admin.organization.posts');
        Route::middleware(['web', EnsureTenantSelected::class])->get('/organization/post', static fn (): string => Blade::render(
            '<html><body>@livewire(\'post-title\', [\'post\' => $post])</body></html>',
            ['post' => Post::query()->where('title', 'a')->firstOrFail()],
        ))->name('admin.organization.post');
        Route::middleware('web')->get('/member/area', static fn (): string => Blade::render('<html><body>@livewire(\'area-probe\')</body></html>'))->name('admin.member.area');
    }

    #[Test]
    public function a_platform_component_action_runs_under_the_bypass(): void
    {
        $admin = User::factory()->create();
        $this->assignRoleInTeam($admin, Role::ADMIN, null);

        $page = $this->actingAs($admin)->get('/platform/posts')->assertOk();

        $this->callAction($page, 'load')
            ->assertOk()
            ->assertSee('count:2');
    }

    #[Test]
    public function an_organization_component_action_is_refused_once_the_tenant_is_gone(): void
    {
        $member = User::factory()->create();
        resolve(TenantMembership::class)->add($member, $this->acme, Role::MEMBER);

        $page = $this->actingAs($member)
            ->withSession([TenantResolver::sessionKey() => $this->acme->id])
            ->get('/organization/posts')
            ->assertOk();

        resolve(TenantMembership::class)->remove($member, $this->acme);

        $this->callAction($page, 'load')->assertForbidden();
    }

    #[Test]
    public function a_snapshot_replayed_under_another_tenant_does_not_hydrate_its_model(): void
    {
        $member = User::factory()->create();
        resolve(TenantMembership::class)->add($member, $this->acme, Role::MEMBER);
        resolve(TenantMembership::class)->add($member, $this->globex, Role::MEMBER);

        $page = $this->actingAs($member)
            ->withSession([TenantResolver::sessionKey() => $this->acme->id])
            ->get('/organization/post')
            ->assertOk();

        $this->callAction($page, 'load')->assertOk()->assertSee('title:a');

        $this->withSession([TenantResolver::sessionKey() => $this->globex->id]);

        $this->callAction($page, 'load')->assertNotFound();
    }

    #[Test]
    public function a_component_action_sees_the_area_of_the_page_it_was_rendered_on(): void
    {
        $page = $this->actingAs(User::factory()->create())->get('/member/area')->assertOk();

        $this->callAction($page, 'load')->assertOk()->assertSee('area:member');
    }

    /**
     * @param  TestResponse<Response>  $page
     * @return TestResponse<Response>
     */
    private function callAction(TestResponse $page, string $method): TestResponse
    {
        $html = $page->getContent();
        $snapshot = htmlspecialchars_decode((string) str(is_string($html) ? $html : '')->betweenFirst('wire:snapshot="', '"'), ENT_QUOTES | ENT_SUBSTITUTE);

        return $this->withHeader('X-Livewire', '1')->postJson(resolve(HandleRequests::class)->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [['path' => '', 'method' => $method, 'params' => []]],
            ]],
        ]);
    }
}
