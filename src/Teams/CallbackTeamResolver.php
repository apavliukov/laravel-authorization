<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Teams;

use AlexPavliukov\Authorization\Contracts\TeamResolver;
use Closure;
use Illuminate\Http\Request;

final readonly class CallbackTeamResolver implements TeamResolver
{
    /** @param Closure(Request): (int|string|null) $callback */
    public function __construct(private Closure $callback) {}

    public function resolve(Request $request): int|string|null
    {
        $teamId = ($this->callback)($request);

        return is_int($teamId) || is_string($teamId) ? $teamId : null;
    }
}
