<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization;

use AlexPavliukov\Authorization\Support\ModelHasRolesQuery;
use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\Tenancy\EnsurePlatformArea;
use AlexPavliukov\Authorization\Tenancy\EnsureTenantSelected;
use AlexPavliukov\Authorization\Tenancy\OwnershipException;
use AlexPavliukov\Authorization\Tenancy\SetCurrentTenant;
use AlexPavliukov\Authorization\Tenancy\TenantMembership;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
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
    }

    public function boot(): void
    {
        // Super-admin bypass: holders of a super-admin role pass every gate check. With
        // Spatie teams on, only the GLOBAL assignment counts (see isSuperAdmin()).
        // Never false — that would veto a granted permission.
        $manager = $this->app->make(AuthorizationManager::class);
        Gate::before(static fn (Authenticatable $user): ?bool => $manager->isSuperAdmin($user) ? true : null);
        // Always: "platform admin without tenants" is a config state, not an app-side Gate::define.
        Gate::define(EnsurePlatformArea::GATE, static fn (): bool => false);

        $tenancy = $this->app->make(CurrentTenant::class);

        if ($tenancy->isConfigured()) {
            $tenancy->assertValidConfig();
            $this->guardOwnerDeletion($tenancy);

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
            $this->publishes([
                __DIR__.'/stubs/AuthorizationServiceProvider.stub' => app_path('Providers/AuthorizationServiceProvider.php'),
            ], 'authorization-provider');

            $this->publishes([
                __DIR__.'/../config/authorization.php' => config_path('authorization.php'),
            ], 'authorization-config');
        }
    }

    /**
     * An owner cannot be deleted. Eloquent fires `deleting` for soft and hard
     * deletes alike; query-builder deletes bypass it (the FK still stops a hard one).
     */
    private function guardOwnerDeletion(CurrentTenant $tenancy): void
    {
        if ($tenancy->ownerKey() === null) {
            return;
        }

        $userClass = $this->app->make(ModelHasRolesQuery::class)->userClass();

        Event::listen('eloquent.deleting: '.$userClass, static function (Authenticatable $user): void {
            if (resolve(TenantMembership::class)->ownsAnyTenant($user)) {
                throw OwnershipException::userOwnsTenants();
            }
        });
    }
}
