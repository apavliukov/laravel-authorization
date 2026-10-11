<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Support\ModelHasRolesQuery;
use AlexPavliukov\Authorization\Support\ScalarKey;
use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Membership = a Spatie role held within the tenant's team — the only record of
 * "which tenants does this user belong to". Writes run under the target team and
 * restore the active one; memoized role reads are flushed.
 *
 * Ownership (when `tenancy.owner_role` is set) lives in the tenant's `owner_key`
 * column; the owner's role row is derived from it and written only by
 * transferOwnership(). The column never grants access by itself.
 */
final readonly class TenantMembership
{
    public function __construct(
        private AuthorizationManager $manager,
        private ModelHasRolesQuery $roles,
        private CurrentTenant $tenancy,
    ) {}

    /** Global (`team_id NULL`) assignment — super-admin / platform roles only. */
    public function assignGlobal(Authenticatable $user, BackedEnum|string $role): void
    {
        $this->assign($user, null, $role);
    }

    public function removeGlobal(Authenticatable $user, BackedEnum|string $role): void
    {
        $this->manager->withTeam(null, static function () use ($user, $role): void {
            /** @phpstan-ignore method.notFound (the package requires the user to use Spatie\Permission\Traits\HasRoles) */
            $user->removeRole(ModelHasRolesQuery::roleName($role));
        });

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
        $this->guardOwnership($user, $tenant, ModelHasRolesQuery::roleName($role));
        $this->guardSingleMembership($user, self::key($tenant));
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
        $this->guardOwnership($user, $tenant, ModelHasRolesQuery::roleName($role));
        $this->guardSingleMembership($user, self::key($tenant));

        $this->manager->withTeam(self::key($tenant), static function () use ($user, $role): void {
            /** @phpstan-ignore method.notFound (the package requires the user to use Spatie\Permission\Traits\HasRoles) */
            $user->syncRoles([ModelHasRolesQuery::roleName($role)]);
        });

        $this->manager->forgetUserRoles($user);
    }

    public function remove(Authenticatable $user, Model|int|string $tenant): void
    {
        $this->guardOwnership($user, $tenant);

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

    /**
     * Make `$newOwner` the tenant's owner: the `owner_key` column and the owner role
     * row change in one transaction; the previous owner keeps `$demoteTo` in the
     * team, or nothing. Idempotent — call it right after creating a tenant with
     * `owner_key` set to write the owner's role row.
     */
    public function transferOwnership(Model $tenant, Authenticatable $newOwner, BackedEnum|string|null $demoteTo = null): void
    {
        [$ownerKey, $ownerRole] = $this->tenancy->requireOwnership();
        $teamId = self::key($tenant);
        $demoteRole = $demoteTo === null ? null : ModelHasRolesQuery::roleName($demoteTo);

        if ($demoteRole === $ownerRole) {
            throw OwnershipException::ownerRoleIsReserved();
        }

        if (method_exists($newOwner, 'trashed') && $newOwner->trashed() === true) {
            throw OwnershipException::archivedUserCannotOwn();
        }

        $this->guardSingleMembership($newOwner, $teamId);

        $newId = self::userKey($newOwner);
        $previous = null;

        try {
            $tenant->getConnection()->transaction(function () use ($ownerKey, $ownerRole, $teamId, $newOwner, $newId, $demoteRole, &$previous): void {
                // A fresh locked row, not the passed model (stale or partial): two concurrent
                // transfers can't demote the same previous owner, and save() still fires model events.
                $locked = $this->tenancy->modelClass()::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($teamId);
                $previousId = ScalarKey::normalize($locked->getAttribute($ownerKey));
                $previous = ScalarKey::same($previousId, $newId) ? null : $this->findUser($previousId);

                $locked->setAttribute($ownerKey, $newId);
                $locked->save();

                $this->manager->withTeam($teamId, static function () use ($newOwner, $ownerRole, $previous, $demoteRole): void {
                    /** @phpstan-ignore method.notFound (the package requires the user to use Spatie\Permission\Traits\HasRoles) */
                    $newOwner->syncRoles([$ownerRole]);
                    /** @phpstan-ignore method.notFound (the package requires the user to use Spatie\Permission\Traits\HasRoles) */
                    $previous?->syncRoles($demoteRole === null ? [] : [$demoteRole]);
                });
            });

            $tenant->setAttribute($ownerKey, $newId);
            $tenant->syncOriginalAttribute($ownerKey);
        } finally {
            $this->manager->forgetUserRoles($newOwner);

            if ($previous instanceof Authenticatable) {
                $this->manager->forgetUserRoles($previous);
            }
        }
    }

    /** The owning user, read through the `owner_key` column. */
    public function ownerOf(Model $tenant): ?Authenticatable
    {
        return $this->findUser($this->ownerIdOf(self::key($tenant), $this->tenancy->requireOwnership()[0]));
    }

    /** Whether the user is the tenant's owner by the `owner_key` column (false while ownership is off). */
    public function isOwner(Authenticatable $user, Model|int|string $tenant): bool
    {
        $ownerKey = $this->tenancy->ownerKey();

        if ($ownerKey === null) {
            return false;
        }

        return ScalarKey::same($this->ownerIdOf(self::key($tenant), $ownerKey), self::userKey($user));
    }

    /** Whether the user owns any tenant, archived ones included (false while ownership is off). */
    public function ownsAnyTenant(Authenticatable $user): bool
    {
        $ownerKey = $this->tenancy->ownerKey();

        return $ownerKey !== null
            && $this->tenancy->modelClass()::query()->withoutGlobalScopes()->where($ownerKey, self::userKey($user))->exists();
    }

    /**
     * Refuse any edit of the owner's membership, and handing the owner role to
     * anyone through a membership write. Re-asserting the owner's own role is a no-op.
     */
    private function guardOwnership(Authenticatable $user, Model|int|string $tenant, ?string $roleName = null): void
    {
        $ownerRole = $this->tenancy->ownerKey() === null ? null : $this->tenancy->ownerRole();
        $isOwner = $this->isOwner($user, $tenant);

        if ($isOwner && $roleName !== $ownerRole) {
            throw OwnershipException::ownerRoleIsDerived();
        }

        if (! $isOwner && $ownerRole !== null && $roleName === $ownerRole) {
            throw OwnershipException::ownerRoleIsReserved();
        }
    }

    private function guardSingleMembership(Authenticatable $user, int|string $teamId): void
    {
        if (! $this->tenancy->singleMembership()) {
            return;
        }

        foreach ($this->tenantIdsOf($user) as $held) {
            if (! ScalarKey::same($held, $teamId)) {
                throw MembershipPolicyException::singleMembership();
            }
        }
    }

    /**
     * The owner id as stored — never the passed model's attribute, which may be
     * stale or not selected at all.
     */
    private function ownerIdOf(int|string $teamId, string $ownerKey): int|string|null
    {
        return ScalarKey::normalize($this->tenancy->modelClass()::query()->withoutGlobalScopes()->whereKey($teamId)->value($ownerKey));
    }

    /** Scopes off: an owner is never soft-deleted, and a stale id must not silently skip a demotion. */
    private function findUser(int|string|null $id): ?Authenticatable
    {
        $user = $id === null ? null : $this->roles->userClass()::query()->withoutGlobalScopes()->find($id);

        return $user instanceof Authenticatable ? $user : null;
    }

    private static function userKey(Authenticatable $user): int|string
    {
        return ScalarKey::normalize($user->getAuthIdentifier()) ?? throw new InvalidArgumentException('User has no scalar key.');
    }

    private static function key(Model|int|string $tenant): int|string
    {
        if (! $tenant instanceof Model) {
            return $tenant;
        }

        return ScalarKey::normalize($tenant->getKey()) ?? throw new InvalidArgumentException('Tenant has no scalar key.');
    }
}
