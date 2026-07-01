<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization;

use AlexPavliukov\Authorization\Contracts\AuthorizationRole;
use AlexPavliukov\Authorization\Contracts\BypassStrategy;
use AlexPavliukov\Authorization\Contracts\TeamResolver;
use AlexPavliukov\Authorization\Support\ModelHasRolesQuery;
use AlexPavliukov\Authorization\Support\ScalarKey;
use AlexPavliukov\Authorization\Support\TeamScope;
use AlexPavliukov\Authorization\Support\UserPermissionMemo;
use AlexPavliukov\Authorization\Teams\CallbackTeamResolver;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class AuthorizationManager
{
    /** @var class-string<AuthorizationRole&BackedEnum>|null */
    private ?string $roleEnum = null;

    /** @var array<int, class-string> */
    private array $models = [];

    /** @var array<int, AuthorizationRole&BackedEnum>|null */
    private ?array $superAdminRoles = null;

    private ?Closure $tenantResolver = null;

    private string $tenantColumn = 'tenant_id';

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

        return $this->teamAwareRoles()->userHasRole($user, ModelHasRolesQuery::roleName($role), TeamScope::Global);
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

        return $this->teamAwareRoles()->userHasRole($user, ModelHasRolesQuery::roleName($role), TeamScope::Team, $teamId);
    }

    /**
     * Whether the user holds the role in any team (or globally), independent of
     * the active permissions team.
     */
    public function userHasRole(Authenticatable $user, BackedEnum|string $role): bool
    {
        if (! $user instanceof Model) {
            return false;
        }

        return $this->teamAwareRoles()->userHasRole($user, ModelHasRolesQuery::roleName($role), TeamScope::Any);
    }

    /**
     * The names of the roles the user holds in the given team (or globally when
     * $teamId is null), independent of the active permissions team.
     *
     * @return list<string>
     */
    public function userRolesInTeam(Authenticatable $user, int|string|null $teamId): array
    {
        if (! $user instanceof Model) {
            return [];
        }

        return $this->teamAwareRoles()->rolesInTeam($user, $teamId);
    }

    /**
     * Drop the request-scoped memo of a user's team-aware role reads — call after
     * mutating that user's roles within the same request, before reading again.
     * A role change alters effective permissions, so this also flushes the user's
     * memoized permission verdicts.
     */
    public function forgetUserRoles(Authenticatable $user): void
    {
        if (! $user instanceof Model) {
            return;
        }

        $this->teamAwareRoles()->forget($user);
        $this->teamAwarePermissions()->forget($user);
    }

    /**
     * Whether the user is granted the permission, memoized per request for the
     * triple (user, permission, active permissions team). Accepts a permission
     * name directly, or an (ability, model) pair resolved via PermissionRegistry.
     *
     * Wraps `$user->can()`, so Gate::before bypass and policies still apply. The
     * memo assumes the verdict is a pure function of (user, permission, active
     * team) within the request; after mutating grants mid-request, flush via
     * forgetUserPermissions() (or forgetUserRoles() for role changes).
     *
     * @param  Authenticatable&Authorizable  $user
     */
    public function userCan(Authenticatable $user, BackedEnum|string $permission, Model|string|null $model = null): bool
    {
        $permissionName = $this->permissionName($permission, $model);

        if (! $user instanceof Model) {
            return $user->can($permissionName);
        }

        return $this->teamAwarePermissions()->remember(
            $user,
            $permissionName,
            ScalarKey::normalize(getPermissionsTeamId()),
            static fn (): bool => $user->can($permissionName),
        );
    }

    /**
     * Drop the request-scoped memo of a user's permission verdicts — call after
     * granting or revoking that user's permissions within the same request, before
     * reading again. Role mutations are covered by forgetUserRoles().
     */
    public function forgetUserPermissions(Authenticatable $user): void
    {
        if (! $user instanceof Model) {
            return;
        }

        $this->teamAwarePermissions()->forget($user);
    }

    private function permissionName(BackedEnum|string $permission, Model|string|null $model): string
    {
        if (! $permission instanceof BackedEnum) {
            return $permission;
        }

        if ($model === null) {
            throw new RuntimeException('A model instance or class-string is required to resolve a permission name from an ability enum.');
        }

        return resolve(PermissionRegistry::class)->nameFromAbility($permission, $model);
    }

    /**
     * The highest-priority role the user holds, by the role enum's declaration
     * order — the first case the user holds wins. Uses team-agnostic identity, so
     * it suits routing/landing regardless of the active team. Null if none.
     *
     * @return (AuthorizationRole&BackedEnum)|null
     */
    public function primaryRole(Authenticatable $user): ?AuthorizationRole
    {
        foreach ($this->roleEnum()::cases() as $role) {
            if ($this->userHasRole($user, $role)) {
                return $role;
            }
        }

        return null;
    }

    private function teamAwareRoles(): ModelHasRolesQuery
    {
        return resolve(ModelHasRolesQuery::class);
    }

    private function teamAwarePermissions(): UserPermissionMemo
    {
        return resolve(UserPermissionMemo::class);
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
     * Declare how to read the current tenant id from the authenticated user — the
     * closure receives the authenticated user and returns its tenant id (or null).
     * `TenantScopedPolicy` uses it to fence models to the user's tenant. Typing the
     * closure's parameter as the app's concrete user model is fine and encouraged.
     */
    public function resolveTenantUsing(Closure $resolver): void
    {
        $this->tenantResolver = $resolver;
    }

    public function currentTenant(Authenticatable $user): int|string|null
    {
        if ($this->tenantResolver === null) {
            throw new RuntimeException('Tenant resolver is not configured. Call Authorization::resolveTenantUsing() in AuthorizationServiceProvider.');
        }

        return ScalarKey::normalize(($this->tenantResolver)($user));
    }

    /** Set the default owning column TenantScopedPolicy reads (default: `tenant_id`). */
    public function tenantColumn(string $column): void
    {
        $this->tenantColumn = $column;
    }

    public function tenantColumnName(): string
    {
        return $this->tenantColumn;
    }

    /**
     * Register a deny-by-default gate for each case of an app system-ability enum,
     * so only the super-admin bypass grants them. The app keeps the enum; the
     * package wires the gates. Use for bypass-only platform gates; if a non-super
     * role must hold a system ability, define that gate yourself instead.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public function systemAbilities(string $enum): void
    {
        foreach ($enum::cases() as $case) {
            Gate::define((string) $case->value, static fn (): bool => false);
        }
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
