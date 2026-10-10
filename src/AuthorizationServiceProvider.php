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
use AlexPavliukov\Authorization\Support\UserPermissionMemo;
use AlexPavliukov\Authorization\Teams\DefaultTeamResolver;
use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\Tenancy\EnsurePlatformArea;
use AlexPavliukov\Authorization\Tenancy\EnsureTenantSelected;
use AlexPavliukov\Authorization\Tenancy\SetCurrentTenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

final class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/authorization.php', 'authorization');

        $this->app->singleton(AuthorizationManager::class);
        $this->app->singleton(CurrentTenant::class);
        $this->app->scoped(ModelHasRolesQuery::class);
        $this->app->scoped(UserPermissionMemo::class);
        $this->app->bind(TeamResolver::class, DefaultTeamResolver::class);
        $this->app->singleton(BypassStrategy::class, RoleBypass::class);
    }

    public function boot(): void
    {
        BypassGate::register();
        // Always: "platform admin without tenants" is a config state, not an app-side Gate::define.
        Gate::define(EnsurePlatformArea::GATE, static fn (): bool => false);

        if ($this->app->make(CurrentTenant::class)->isConfigured()) {
            Context::hydrated(static function (): void {
                resolve(CurrentTenant::class)->syncPermissionsTeam();
            });
            $this->app->make(Kernel::class)
                ->addToMiddlewarePriorityBefore(SubstituteBindings::class, SetCurrentTenant::class);

            if (class_exists(Livewire::class)) {
                Livewire::addPersistentMiddleware([SetCurrentTenant::class, EnsureTenantSelected::class, EnsurePlatformArea::class]);
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([MakePolicyCommand::class, SyncCommand::class]);

            $this->publishes([
                __DIR__.'/stubs/AuthorizationServiceProvider.stub' => app_path('Providers/AuthorizationServiceProvider.php'),
            ], 'authorization-provider');

            $this->publishes([
                __DIR__.'/../config/authorization.php' => config_path('authorization.php'),
            ], 'authorization-config');
        }
    }
}
