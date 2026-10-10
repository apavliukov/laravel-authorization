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
 * 2. Otherwise the user's sole team. Several teams and no session choice ⇒ null —
 *    never an arbitrary first row.
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

        return count($ids) === 1 ? $ids[0] : null;
    }

    private function mayEnter(Authenticatable $user, int|string $id): bool
    {
        if ($this->membership->isMember($user, $id)) {
            return true;
        }

        if (! $this->manager->isGlobalSuperAdmin($user)) {
            return false;
        }

        return $this->tenant->modelClass()::query()->withoutGlobalScopes()->whereKey($id)->exists();
    }

    public static function sessionKey(): string
    {
        $key = config('authorization.tenancy.session_key', 'current_organization_id');

        return is_string($key) ? $key : 'current_organization_id';
    }
}
