<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Request-scoped memo of permission decisions, keyed by user identity, permission
 * name and the active permissions team. The service is bound `scoped`, so it is
 * flushed per request / queue job — no decision leaks across users, teams or jobs.
 *
 * Sits beside {@see ModelHasRolesQuery}: that memoizes raw role reads against the
 * pivot, this memoizes the resolved `$user->can()` verdict so auth-aware query
 * scopes don't re-run the whole Gate pipeline on every query.
 */
final class UserPermissionMemo
{
    /** @var array<string, bool> */
    private array $cache = [];

    /**
     * The memoized verdict for the (user, permission, team) triple, computing it
     * via $resolver on first read. A `false` verdict is cached too — only a first
     * read misses.
     *
     * @param  Closure(): bool  $resolver
     */
    public function remember(Model $user, string $permissionName, int|string|null $teamId, Closure $resolver): bool
    {
        $key = $this->cacheKey($user).'|'.$permissionName.'|'.($teamId ?? 'null');

        return $this->cache[$key] ??= $resolver();
    }

    /**
     * Drop every memoized verdict for a single user — call after mutating that
     * user's roles or permissions within the same request, before reading again.
     */
    public function forget(Model $user): void
    {
        $prefix = $this->cacheKey($user).'|';

        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->cache[$key]);
            }
        }
    }

    private function cacheKey(Model $user): string
    {
        $key = $user->getKey();

        return $user->getMorphClass().'|'.(is_scalar($key) ? (string) $key : '');
    }
}
