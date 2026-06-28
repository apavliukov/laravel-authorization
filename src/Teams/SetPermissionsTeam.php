<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Teams;

use AlexPavliukov\Authorization\AuthorizationManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetPermissionsTeam
{
    public function __construct(private AuthorizationManager $manager) {}

    public function handle(Request $request, Closure $next): Response
    {
        $teamId = $this->manager->teamResolver()->resolve($request);

        if ($teamId !== null) {
            setPermissionsTeamId($teamId);
        }

        return $next($request);
    }
}
