<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface BypassStrategy
{
    /**
     * Whether this user short-circuits Gate::before for the given ability,
     * granting access without consulting Spatie permissions or policies.
     */
    public function shouldBypass(Authenticatable $user, string $ability): bool;
}
