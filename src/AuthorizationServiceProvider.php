<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization;

use AlexPavliukov\Authorization\Console\MakePolicyCommand;
use AlexPavliukov\Authorization\Console\SyncCommand;
use AlexPavliukov\Authorization\Contracts\BypassStrategy;
use AlexPavliukov\Authorization\Contracts\TeamResolver;
use AlexPavliukov\Authorization\Support\BypassGate;
use AlexPavliukov\Authorization\Support\ModelHasRolesQuery;
use AlexPavliukov\Authorization\Support\RoleBypass;
use AlexPavliukov\Authorization\Teams\DefaultTeamResolver;
use AlexPavliukov\Authorization\Teams\SetPermissionsTeam;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuthorizationManager::class);
        $this->app->scoped(ModelHasRolesQuery::class);
        $this->app->bind(TeamResolver::class, DefaultTeamResolver::class);
        $this->app->singleton(BypassStrategy::class, RoleBypass::class);
    }

    public function boot(): void
    {
        BypassGate::register();

        if (config('permission.teams') === true) {
            $this->app->make(Router::class)
                ->pushMiddlewareToGroup('web', SetPermissionsTeam::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([MakePolicyCommand::class, SyncCommand::class]);

            $this->publishes([
                __DIR__.'/stubs/AuthorizationServiceProvider.stub' => app_path('Providers/AuthorizationServiceProvider.php'),
            ], 'authorization-provider');
        }
    }
}
