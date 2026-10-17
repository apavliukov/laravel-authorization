<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization;

use AlexPavliukov\Authorization\Contracts\AuthorizationRole;
use AlexPavliukov\Authorization\Support\ModelHasRolesQuery;
use AlexPavliukov\Authorization\Support\TeamScope;
use BackedEnum;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

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
     * Whether the user holds a super-admin role as a GLOBAL assignment
     * (`team_id IS NULL`) — team-agnostic identity, the basis of the bypass.
     */
    public function isGlobalSuperAdmin(Authenticatable $user): bool
    {
        return array_any(
            $this->superAdminRoles(),
            fn (BackedEnum $role): bool => $this->userHasGlobalRole($user, $role),
        );
    }

    /**
     * Super-admin identity regardless of the active team: with Spatie teams on,
     * only the global assignment counts (isGlobalSuperAdmin); without teams, any
     * super-admin role. The basis of the Gate::before bypass and `is_platform_admin`.
     */
    public function isSuperAdmin(Authenticatable $user): bool
    {
        if (resolve(PermissionRegistrar::class)->teams) {
            return $this->isGlobalSuperAdmin($user);
        }

        return array_any($this->superAdminRoles(), fn (BackedEnum $role): bool => $this->userHasRole($user, $role));
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
     */
    public function forgetUserRoles(Authenticatable $user): void
    {
        if ($user instanceof Model) {
            $this->teamAwareRoles()->forget($user);
        }
    }

    /**
     * `$user->can()` for a permission name, or an (ability, model) pair resolved
     * via PermissionRegistry — Gate::before bypass and policies still apply.
     *
     * @param  Authenticatable&Authorizable  $user
     */
    public function userCan(Authenticatable $user, BackedEnum|string $permission, Model|string|null $model = null): bool
    {
        return $user->can($this->permissionName($permission, $model));
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
     * The highest-priority role the user holds, by the role enum's byPriority()
     * list — the first case the user holds wins. Uses team-agnostic identity, so
     * it suits routing/landing regardless of the active team. Null if none.
     *
     * @return (AuthorizationRole&BackedEnum)|null
     */
    public function primaryRole(Authenticatable $user): ?AuthorizationRole
    {
        foreach ($this->roleEnum()::byPriority() as $role) {
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
}
