<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures;

use AlexPavliukov\Authorization\Contracts\AuthorizationRole;

enum Role: string implements AuthorizationRole
{
    case ADMIN = 'admin';
    case MEMBER = 'member';
    case EDITOR = 'editor';
    case OWNER = 'owner';

    /** Deliberately not declaration order, so tests prove the list wins. */
    public static function byPriority(): array
    {
        return [self::ADMIN, self::OWNER, self::EDITOR, self::MEMBER];
    }

    public function isSuperAdmin(): bool
    {
        return $this === self::ADMIN;
    }

    /** @return array<int, string> */
    public function permissions(): array
    {
        return match ($this) {
            self::EDITOR => ['view users', 'update users'],
            self::ADMIN, self::MEMBER, self::OWNER => [],
        };
    }
}
