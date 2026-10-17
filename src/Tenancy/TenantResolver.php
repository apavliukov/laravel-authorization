<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Support\ScalarKey;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * The one way a request gets its tenant. Membership = a Spatie role held in the
 * tenant's team (`model_has_roles.team_id`), nothing else.
 *
 * 1. Session key, honoured only while the user is a member of that tenant (a
 *    global super-admin may enter any tenant that exists); a stale key is dropped.
 * 2. Otherwise the user's sole existing tenant. Still ambiguous ⇒ null — never
 *    an arbitrary first row.
 * Spatie puts no FK on team_id, so a membership may outlive its tenant: a tenant
 * whose row is gone (soft-deleted included) is never activated, whichever path
 * found it.
 */
final readonly class TenantResolver
{
    public function __construct(
        private AuthorizationManager $manager,
        private TenantMembership $membership,
        private CurrentTenant $tenant,
    ) {}

    public function resolve(Request $request): int|string|null
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $fromSession = $request->hasSession() ? ScalarKey::normalize($request->session()->get(self::sessionKey())) : null;

        if ($fromSession !== null) {
            if ($this->mayEnter($user, $fromSession)) {
                return $fromSession;
            }

            $request->session()->forget(self::sessionKey());
        }

        $ids = $this->membership->tenantIdsOf($user);

        if ($ids === []) {
            return null;
        }

        $query = $this->tenant->modelClass()::query();
        $keys = $query->whereKey($ids)->limit(2)->pluck($query->getModel()->getKeyName())->all();

        return count($keys) === 1 ? ScalarKey::normalize($keys[0]) : null;
    }

    private function mayEnter(Authenticatable $user, int|string $id): bool
    {
        return ($this->membership->isMember($user, $id) || $this->manager->isGlobalSuperAdmin($user))
            && $this->tenant->modelClass()::query()->whereKey($id)->exists();
    }

    public static function sessionKey(): string
    {
        $key = config('authorization.tenancy.session_key', 'current_organization_id');

        return is_string($key) ? $key : 'current_organization_id';
    }
}
