<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Support;

use AlexPavliukov\Authorization\Contracts\BypassStrategy;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * No god-mode: every check goes through Spatie permissions and policies.
 * Use when there must be no super-admin short-circuit at the authorization layer.
 */
final class NoBypass implements BypassStrategy
{
    public function shouldBypass(Authenticatable $user, string $ability): bool
    {
        return false;
    }
}
