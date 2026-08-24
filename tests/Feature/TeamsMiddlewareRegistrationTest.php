<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature;

use AlexPavliukov\Authorization\AuthorizationServiceProvider;
use AlexPavliukov\Authorization\Teams\SetPermissionsTeam;
use AlexPavliukov\Authorization\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('policies')]
#[CoversClass(AuthorizationServiceProvider::class)]
final class TeamsMiddlewareRegistrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        /** @var Application $app */
        $app['config']->set('permission.teams', true);
        $app['config']->set('authorization.tenancy.model', null);
    }

    #[Test]
    public function teams_without_tenancy_leave_the_web_group_untouched(): void
    {
        $this->assertTrue(config('permission.teams'));
        $this->assertNotContains(SetPermissionsTeam::class, resolve(Router::class)->getMiddlewareGroups()['web'] ?? []);
    }
}
