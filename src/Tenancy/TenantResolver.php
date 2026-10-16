<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Support\ScalarKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The one way a request gets its tenant. Membership = a Spatie role held in the
 * tenant's team (`model_has_roles.team_id`), nothing else.
 *
 * 1. Session key, honoured only while the user is a member of that tenant (a
 *    global super-admin may enter any tenant that exists); a stale key is dropped
 *    and TenantChoiceDropped dispatched.
 * 2. Otherwise the user's sole enterable team.
 * 3. Otherwise, with `tenancy.fallback_to_owned`, the sole enterable team they own.
 *    Still ambiguous ⇒ null — never an arbitrary first row.
 * Enterable = the tenant row exists and passes `tenancy.enterable_scope`; any
 * other tenant is never activated, whichever path found it.
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
            event(new TenantChoiceDropped($user, $fromSession));
        }

        $ids = $this->membership->tenantIdsOf($user);

        if ($ids === []) {
            return null;
        }

        $sole = $this->soleKey($this->enterable()->whereKey($ids));
        $ownerKey = $this->tenant->ownerKey();

        if ($sole !== null || $ownerKey === null || ! $this->tenant->fallsBackToOwned()) {
            return $sole;
        }

        return $this->soleKey($this->enterable()->whereKey($ids)->where($ownerKey, $user->getAuthIdentifier()));
    }

    /**
     * Make $tenant the user's session choice and activate it for this request —
     * under the same rule the session path of resolve() enforces.
     *
     * @throws AuthorizationException when the user may not enter it
     */
    public function choose(Authenticatable $user, Model|int|string $tenant): void
    {
        $id = $tenant instanceof Model ? ScalarKey::normalize($tenant->getKey()) : $tenant;
        $model = $id !== null && $this->admits($user, $id) ? $this->enterable()->find($id) : null;

        if (! $model instanceof Model) {
            throw new AuthorizationException('You may not enter this tenant.');
        }

        session()->put(self::sessionKey(), $model->getKey());
        $this->tenant->set($model);
    }

    /** Drop the session choice and deactivate; the next request falls back to the sole enterable tenant. */
    public function clearChoice(): void
    {
        session()->forget(self::sessionKey());
        $this->tenant->forget();
    }

    /** @param  Builder<Model>  $query */
    private function soleKey(Builder $query): int|string|null
    {
        $keys = $query->limit(2)->pluck($query->getModel()->getKeyName())->all();

        return count($keys) === 1 ? ScalarKey::normalize($keys[0]) : null;
    }

    private function mayEnter(Authenticatable $user, int|string $id): bool
    {
        return $this->admits($user, $id) && $this->enterable()->whereKey($id)->exists();
    }

    private function admits(Authenticatable $user, int|string $id): bool
    {
        return $this->membership->isMember($user, $id) || $this->manager->isGlobalSuperAdmin($user);
    }

    /**
     * Spatie puts no FK on team_id: a membership may outlive its tenant (soft-deleted
     * ones count as gone). The app's enterable scope (e.g. `active`) narrows further.
     *
     * @return Builder<Model>
     */
    private function enterable(): Builder
    {
        $query = $this->tenant->modelClass()::query();
        $scope = config('authorization.tenancy.enterable_scope');

        if (is_string($scope)) {
            $query->scopes($scope);
        }

        return $query;
    }

    public static function sessionKey(): string
    {
        $key = config('authorization.tenancy.session_key', 'current_organization_id');

        return is_string($key) ? $key : 'current_organization_id';
    }
}
