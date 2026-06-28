<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Teams;

use AlexPavliukov\Authorization\Contracts\TeamResolver;
use Illuminate\Http\Request;

final class DefaultTeamResolver implements TeamResolver
{
    public function resolve(Request $request): int|string|null
    {
        $configValue = config('permission.team_foreign_key', 'team_id');
        $foreignKey = is_string($configValue) ? $configValue : 'team_id';
        $teamId = $request->user()?->getAttribute($foreignKey);

        return is_int($teamId) || is_string($teamId) ? $teamId : null;
    }
}
