<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures;

use AlexPavliukov\Authorization\Contracts\AuthorizationRole;

/** byPriority() skips two cases and repeats one — PermissionSync must refuse it. */
enum IncompletePriorityRole: string implements AuthorizationRole
{
    case FIRST = 'first';
    case SECOND = 'second';
    case THIRD = 'third';

    public static function byPriority(): array
    {
        return [self::FIRST, self::FIRST];
    }

    public function isSuperAdmin(): bool
    {
        return false;
    }

    /** @return array<int, string> */
    public function permissions(): array
    {
        return [];
    }
}
