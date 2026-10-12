<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\Tenancy\BelongsToTenant;
use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\Tenancy\MissingTenantContextException;
use AlexPavliukov\Authorization\Tenancy\TenantMembership;
use AlexPavliukov\Authorization\Tenancy\TenantScope;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Comment;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Post;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Reaction;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TenancyTestCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('tenancy')]
#[CoversClass(TenantScope::class)]
#[CoversClass(BelongsToTenant::class)]
#[CoversClass(MissingTenantContextException::class)]
final class TenantScopeTest extends TenancyTestCase
{
    private Organization $acme;

    private Organization $globex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::query()->create(['name' => 'Acme']);
        $this->globex = Organization::query()->create(['name' => 'Globex']);

        resolve(CurrentTenant::class)->withoutTenant(function (): void {
            Post::query()->create(['organization_id' => $this->acme->id, 'title' => 'acme post']);
            Post::query()->create(['organization_id' => $this->globex->id, 'title' => 'globex post']);
        });
    }

    #[Test]
    public function queries_are_filtered_to_the_current_tenant(): void
    {
        resolve(CurrentTenant::class)->set($this->acme);

        $this->assertSame(['acme post'], Post::query()->pluck('title')->all());
    }

    #[Test]
    public function no_tenant_throws_in_strict_mode(): void
    {
        $this->expectException(MissingTenantContextException::class);

        Post::query()->get();
    }

    #[Test]
    public function no_tenant_yields_nothing_outside_strict_mode(): void
    {
        config()->set('authorization.tenancy.strict', false);

        $this->assertSame(0, Post::query()->count());
    }

    #[Test]
    public function without_tenant_sees_everything(): void
    {
        $count = resolve(CurrentTenant::class)->withoutTenant(static fn (): int => Post::query()->count());

        $this->assertSame(2, $count);
    }

    #[Test]
    public function creating_autofills_the_tenant_key(): void
    {
        resolve(CurrentTenant::class)->set($this->acme);

        $post = Post::query()->create(['title' => 'new']);

        $this->assertSame($this->acme->id, $post->organization_id);
        $this->assertTrue($this->acme->is($post->tenant));
    }

    #[Test]
    public function creating_without_a_tenant_throws_in_strict_mode(): void
    {
        $this->expectException(MissingTenantContextException::class);

        Post::query()->create(['title' => 'orphan']);
    }

    #[Test]
    public function route_binding_404s_across_tenants(): void
    {
        Route::middleware('web')->get('/posts/{post}', static fn (Post $post): string => $post->title);
        $foreign = resolve(CurrentTenant::class)->withoutTenant(static fn (): Post => Post::query()->where('title', 'globex post')->firstOrFail());

        $user = User::factory()->create();
        resolve(TenantMembership::class)->add($user, $this->acme, Role::MEMBER);

        $this->actingAs($user)->get("/posts/{$foreign->id}")->assertNotFound();
    }

    #[Test]
    public function transitively_owned_models_are_fenced_through_the_relation_path(): void
    {
        resolve(CurrentTenant::class)->withoutTenant(static function (): void {
            foreach (Post::query()->get() as $post) {
                Comment::query()->create(['post_id' => $post->id, 'body' => $post->title]);
            }
        });

        resolve(CurrentTenant::class)->set($this->globex);

        $this->assertSame(['globex post'], Comment::query()->pluck('body')->all());
    }

    #[Test]
    public function transitively_owned_models_fail_closed_without_a_tenant(): void
    {
        $this->expectException(MissingTenantContextException::class);

        Comment::query()->get();
    }

    #[Test]
    public function a_one_hop_path_model_reaches_its_tenant_through_the_hop(): void
    {
        resolve(CurrentTenant::class)->withoutTenant(static function (): void {
            foreach (Post::query()->get() as $post) {
                Comment::query()->create(['post_id' => $post->id, 'body' => $post->title]);
            }
        });

        $comments = resolve(CurrentTenant::class)->withoutTenant(static fn () => Comment::query()->with('tenant')->orderBy('id')->get());

        $this->assertTrue($comments->every(static fn (Comment $c): bool => $c->relationLoaded('tenant')));
        [$acmeComment, $globexComment] = $comments->all();

        $this->assertTrue($this->acme->is($acmeComment->tenant));
        $this->assertTrue($this->globex->is($globexComment->tenant));
    }

    #[Test]
    public function tenant_relation_is_unavailable_on_multi_hop_models(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('reaches its tenant through [comment.post]');

        new Reaction()->tenant();
    }

    #[Test]
    public function creating_under_bypass_without_an_explicit_tenant_key_throws(): void
    {
        $this->expectException(MissingTenantContextException::class);

        resolve(CurrentTenant::class)->withoutTenant(static fn (): Post => Post::query()->create(['title' => 'orphan']));
    }

    #[Test]
    public function find_or_fail_across_tenants_is_not_found(): void
    {
        $foreign = resolve(CurrentTenant::class)->withoutTenant(static fn (): Post => Post::query()->where('title', 'globex post')->firstOrFail());
        resolve(CurrentTenant::class)->set($this->acme);

        $this->expectException(ModelNotFoundException::class);

        Post::query()->findOrFail($foreign->id);
    }
}
