<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Teams;

use AlexPavliukov\Authorization\Contracts\TeamResolver;
use AlexPavliukov\Authorization\Support\ScalarKey;
use Closure;
use Illuminate\Http\Request;

final readonly class CallbackTeamResolver implements TeamResolver
{
    /** @param Closure(Request): (int|string|null) $callback */
    public function __construct(private Closure $callback) {}

    public function resolve(Request $request): int|string|null
    {
        return ScalarKey::normalize(($this->callback)($request));
    }
}
