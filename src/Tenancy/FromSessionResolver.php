<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Contracts\TenantResolver;
use AlexPavliukov\Authorization\Support\ScalarKey;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Many tenants per user; the active one is held in the session and only
 * honoured when the user holds a role in it (membership = Spatie team role).
 * A global super-admin may enter any tenant that exists.
 */
final readonly class FromSessionResolver implements TenantResolver
{
    public function __construct(private AuthorizationManager $manager, private CurrentTenant $tenant) {}

    public function resolve(Request $request): int|string|null
    {
        $user = $request->user();

        if ($user === null || ! $request->hasSession()) {
            return null;
        }

        $id = ScalarKey::normalize($request->session()->get(self::sessionKey()));

        if ($id === null) {
            return null;
        }

        if ($this->mayEnter($user, $id)) {
            return $id;
        }

        $request->session()->forget(self::sessionKey());

        return null;
    }

    private function mayEnter(Authenticatable $user, int|string $id): bool
    {
        if ($this->manager->userRolesInTeam($user, $id) !== []) {
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
