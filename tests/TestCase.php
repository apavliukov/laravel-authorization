<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests;

use AlexPavliukov\Authorization\AuthorizationServiceProvider;
use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use AlexPavliukov\Authorization\Tests\Fixtures\TestAuthorizationServiceProvider;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (Role::cases() as $role) {
            SpatieRole::findOrCreate($role->value, 'web');
        }
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            AuthorizationServiceProvider::class,
            TestAuthorizationServiceProvider::class,
        ];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        tap($app->make('config'), static function (Repository $config): void {
            $config->set('database.default', 'testing');
            $config->set('permission.teams', false);
            $config->set('auth.providers.users.model', User::class);
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();

        $migration = require __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
        $migration->up();
    }
}
