<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Unit;

use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Teams\DefaultTeamResolver;
use AlexPavliukov\Authorization\Teams\SetPermissionsTeam;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('policies')]
#[CoversClass(DefaultTeamResolver::class)]
#[CoversClass(SetPermissionsTeam::class)]
final class TeamsTest extends TestCase
{
    #[Test]
    public function default_resolver_returns_the_users_team_key(): void
    {
        config()->set('permission.team_foreign_key', 'team_id');

        $user = new User;
        $user->setAttribute('team_id', 99);

        $request = Request::create('/');
        $request->setUserResolver(static fn (): User => $user);

        $this->assertSame(99, (new DefaultTeamResolver)->resolve($request));
    }

    #[Test]
    public function default_resolver_returns_null_without_an_authenticated_user(): void
    {
        $this->assertNull((new DefaultTeamResolver)->resolve(Request::create('/')));
    }

    #[Test]
    public function set_permissions_team_middleware_applies_the_resolved_team_id(): void
    {
        config()->set('permission.teams', true);
        config()->set('permission.team_foreign_key', 'team_id');

        $user = new User;
        $user->setAttribute('team_id', 77);

        $request = Request::create('/');
        $request->setUserResolver(static fn (): User => $user);

        $middleware = new SetPermissionsTeam(resolve(AuthorizationManager::class));
        $middleware->handle($request, static fn (Request $request): Response => new Response('ok'));

        $this->assertSame(77, getPermissionsTeamId());
    }
}
