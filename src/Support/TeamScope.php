<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Support;

/**
 * How a team-aware role query treats the pivot `team_id`:
 * - Global: only global assignments (`team_id IS NULL`)
 * - Team: a specific team (`team_id = ?`)
 * - Any: any assignment, regardless of team
 */
enum TeamScope
{
    case Global;
    case Team;
    case Any;
}
