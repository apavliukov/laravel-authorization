<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Support;

/**
 * Narrows a mixed value to a scalar key (int|string) or null — the single
 * definition of what counts as a valid team/tenant identifier across the package.
 */
final class ScalarKey
{
    public static function normalize(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }

    /** Key equality across int/string (`5` from a column vs `'5'` from a route). */
    public static function same(int|string|null $a, int|string|null $b): bool
    {
        return $a !== null && $b !== null && (string) $a === (string) $b;
    }
}
