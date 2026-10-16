<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\Tenancy\CurrentArea;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TenancyTestCase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('tenancy')]
#[CoversClass(CurrentArea::class)]
final class CurrentAreaTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/member/dashboard', static fn (): ?string => resolve(CurrentArea::class)->current())->name('admin.member.dashboard');
        Route::middleware('web')->get('/anywhere', static fn (): ?string => resolve(CurrentArea::class)->current())->name('shared.home');
        Route::middleware('web')->post('/area/{area}', static function (string $area): ?string {
            resolve(CurrentArea::class)->set($area);

            return resolve(CurrentArea::class)->current();
        })->name('shared.area');
    }

    #[Test]
    public function it_resolves_the_area_from_the_route_name_prefix(): void
    {
        $this->actingAs(User::factory()->create())->get('/member/dashboard')->assertSee('member');
    }

    #[Test]
    public function it_falls_back_to_the_session_view_mode_then_the_first_configured_area(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/anywhere')->assertSee('platform');
        $this->actingAs($user)->withoutMiddleware(PreventRequestForgery::class)->post('/area/member')->assertSee('member');
        $this->actingAs($user)->get('/anywhere')->assertSee('member');
    }

    #[Test]
    public function the_view_mode_works_outside_an_http_request(): void
    {
        resolve(CurrentArea::class)->set('member');

        $this->assertSame('member', resolve(CurrentArea::class)->current());
    }

    #[Test]
    public function set_rejects_unknown_areas(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        resolve(CurrentArea::class)->set('moon');
    }

    #[Test]
    public function it_lists_the_configured_areas(): void
    {
        $this->assertSame(['platform', 'organization', 'member'], resolve(CurrentArea::class)->all());
    }
}
