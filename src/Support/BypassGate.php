<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Support;

use AlexPavliukov\Authorization\Contracts\BypassStrategy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Wires the bound BypassStrategy into a single Gate::before hook.
 *
 * The strategy is resolved from the container lazily on each check, so a consumer
 * may override it any time before the check runs — via Authorization::bypassUsing()
 * or by rebinding BypassStrategy::class in the container — without caring about
 * service-provider boot order.
 */
final class BypassGate
{
    public static function register(): void
    {
        Gate::before(static fn (Authenticatable $user, string $ability): ?bool => app(BypassStrategy::class)->shouldBypass($user, $ability) ? true : null);
    }
}
