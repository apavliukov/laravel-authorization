<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization;

use AlexPavliukov\Authorization\Contracts\AuthorizationRole;
use AlexPavliukov\Authorization\Contracts\BypassStrategy;
use AlexPavliukov\Authorization\Contracts\TeamResolver;
use AlexPavliukov\Authorization\Support\ModelHasRolesQuery;
use AlexPavliukov\Authorization\Teams\CallbackTeamResolver;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RuntimeException;

final class AuthorizationManager
{
    /** @var class-string<AuthorizationRole&BackedEnum>|null */
    private ?string $roleEnum = null;

    /** @var array<int, class-string> */
    private array $models = [];

    /** @var array<int, AuthorizationRole&BackedEnum>|null */
    private ?array $superAdminRoles = null;

    /** @param class-string<AuthorizationRole&BackedEnum> $roleEnum */
    public function useRoleEnum(string $roleEnum): void
    {
        $this->roleEnum = $roleEnum;
        $this->superAdminRoles = null;
    }

    /** @return class-string<AuthorizationRole&BackedEnum> */
    public function roleEnum(): string
    {
        return $this->roleEnum ?? throw new RuntimeException('Role enum is not configured. Call Authorization::useRoleEnum() in AuthorizationServiceProvider.');
    }

    /** @param array<int, class-string> $models */
    public function authorizableModels(array $models): void
    {
        $this->models = $models;
    }

    /** @return array<int, class-string> */
    public function models(): array
    {
        return $this->models;
    }

    /**
     * Role cases that bypass Gate::before. Memoized — the role enum is a
     * boot-time declaration consulted on every authorization check.
     *
     * @return array<int, AuthorizationRole&BackedEnum>
     */
    public function superAdminRoles(): array
    {
        if ($this->superAdminRoles !== null) {
            return $this->superAdminRoles;
        }

        $roleEnum = $this->roleEnum();

        return $this->superAdminRoles = array_values(array_filter(
            $roleEnum::cases(),
            static fn (AuthorizationRole $role): bool => $role->isSuperAdmin(),
        ));
    }

    /** @param class-string<TeamResolver>|TeamResolver|Closure(Request): (int|string|null) $resolver */
    public function resolveTeamsUsing(string|TeamResolver|Closure $resolver): void
    {
        if ($resolver instanceof Closure) {
            $resolver = new CallbackTeamResolver($resolver);
        }

        $this->bind(TeamResolver::class, $resolver);
    }

    public function teamResolver(): TeamResolver
    {
        return resolve(TeamResolver::class);
    }

    /**
     * Run a callback under a temporary permissions team, restoring the previous
     * team afterwards — even if the callback throws.
     *
     * @template TReturn
     *
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    public function withTeam(int|string|null $teamId, callable $callback): mixed
    {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($teamId);

        try {
            return $callback();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }

    /**
     * Whether the user holds the role as a global assignment (`team_id IS NULL`),
     * independent of the active permissions team.
     */
    public function userHasGlobalRole(Authenticatable $user, BackedEnum|string $role): bool
    {
        if (! $user instanceof Model) {
            return false;
        }

        return $this->teamAwareRoles()->userHasRole($user, ModelHasRolesQuery::roleName($role), null, true);
    }

    /**
     * Whether the user holds the role assigned within the given team, independent
     * of the active permissions team.
     */
    public function userHasRoleInTeam(Authenticatable $user, BackedEnum|string $role, int|string $teamId): bool
    {
        if (! $user instanceof Model) {
            return false;
        }

        return $this->teamAwareRoles()->userHasRole($user, ModelHasRolesQuery::roleName($role), $teamId, false);
    }

    private function teamAwareRoles(): ModelHasRolesQuery
    {
        return resolve(ModelHasRolesQuery::class);
    }

    /**
     * Override the bypass strategy. BypassGate resolves BypassStrategy from the
     * container lazily, so this takes effect regardless of provider boot order.
     *
     * @param  class-string<BypassStrategy>|BypassStrategy  $strategy
     */
    public function bypassUsing(string|BypassStrategy $strategy): void
    {
        $this->bind(BypassStrategy::class, $strategy);
    }

    /**
     * @param  class-string  $abstract
     * @param  class-string|object  $concrete
     */
    private function bind(string $abstract, string|object $concrete): void
    {
        if (is_object($concrete)) {
            app()->instance($abstract, $concrete);

            return;
        }

        app()->bind($abstract, $concrete);
    }
}
