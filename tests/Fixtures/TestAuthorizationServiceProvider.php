<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures;

use AlexPavliukov\Authorization\Authorization;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Mirrors what a real consumer's published AuthorizationServiceProvider stub does:
 * declares the role enum + authorizable models and defines system abilities.
 */
final class TestAuthorizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Authorization::useRoleEnum(Role::class);

        Authorization::authorizableModels([
            User::class,
        ]);

        Gate::define(SystemAbility::ACCESS_PLATFORM_ADMIN, static fn (): bool => false);
    }
}
