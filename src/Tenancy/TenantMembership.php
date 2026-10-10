<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Support\ModelHasRolesQuery;
use AlexPavliukov\Authorization\Support\ScalarKey;
use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Membership = a Spatie role held within the tenant's team — the only record of
 * "which tenants does this user belong to". Writes run under the target team and
 * restore the active one; memoized role reads are flushed.
 */
final readonly class TenantMembership
{
    public function __construct(private AuthorizationManager $manager, private ModelHasRolesQuery $roles) {}

    /** Global (`team_id NULL`) assignment — super-admin / platform roles only. */
    public function assignGlobal(Authenticatable $user, BackedEnum|string $role): void
    {
        $this->assign($user, null, $role);
    }

    /** Detach every role in every tenant and globally. */
    public function removeEverywhere(Authenticatable $user): void
    {
        if (! $user instanceof Model) {
            return;
        }

        $this->roles->detachAll($user);
        $this->manager->forgetUserRoles($user);
    }

    /**
     * The tenants the user holds any role in, memoized per request.
     *
     * @return list<int|string>
     */
    public function tenantIdsOf(Authenticatable $user): array
    {
        return $user instanceof Model ? $this->roles->teamIdsOf($user) : [];
    }

    public function add(Authenticatable $user, Model|int|string $tenant, BackedEnum|string $role): void
    {
        $this->assign($user, self::key($tenant), $role);
    }

    private function assign(Authenticatable $user, int|string|null $teamId, BackedEnum|string $role): void
    {
        $this->manager->withTeam($teamId, static function () use ($user, $role): void {
            /** @phpstan-ignore method.notFound (the package requires the user to use Spatie\Permission\Traits\HasRoles) */
            $user->assignRole(ModelHasRolesQuery::roleName($role));
        });

        $this->manager->forgetUserRoles($user);
    }

    public function changeRole(Authenticatable $user, Model|int|string $tenant, BackedEnum|string $role): void
    {
        $this->manager->withTeam(self::key($tenant), static function () use ($user, $role): void {
            /** @phpstan-ignore method.notFound (the package requires the user to use Spatie\Permission\Traits\HasRoles) */
            $user->syncRoles([ModelHasRolesQuery::roleName($role)]);
        });

        $this->manager->forgetUserRoles($user);
    }

    public function remove(Authenticatable $user, Model|int|string $tenant): void
    {
        $this->manager->withTeam(self::key($tenant), static function () use ($user): void {
            /** @phpstan-ignore method.notFound (the package requires the user to use Spatie\Permission\Traits\HasRoles) */
            $user->syncRoles([]);
        });

        $this->manager->forgetUserRoles($user);
    }

    /** The user's role name in the tenant (first, if several), or null when not a member. */
    public function roleFor(Authenticatable $user, Model|int|string $tenant): ?string
    {
        return $this->manager->userRolesInTeam($user, self::key($tenant))[0] ?? null;
    }

    public function isMember(Authenticatable $user, Model|int|string $tenant): bool
    {
        return $this->manager->userRolesInTeam($user, self::key($tenant)) !== [];
    }

    private static function key(Model|int|string $tenant): int|string
    {
        if (! $tenant instanceof Model) {
            return $tenant;
        }

        return ScalarKey::normalize($tenant->getKey()) ?? throw new \InvalidArgumentException('Tenant has no scalar key.');
    }
}
