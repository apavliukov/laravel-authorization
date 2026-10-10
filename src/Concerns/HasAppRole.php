<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Concerns;

use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Contracts\AuthorizationRole;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Team-agnostic role identity on the user model: `$user->app_role` (the
 * highest-priority role the user holds anywhere, per enum order) and
 * `$user->is_platform_admin` (super-admin; under teams the global assignment
 * only). Reads are memoized per request — call Authorization::forgetUserRoles()
 * after a role write.
 *
 * @property-read (AuthorizationRole&\BackedEnum)|null $app_role
 * @property-read bool $is_platform_admin
 */
trait HasAppRole
{
    /** @return Attribute<(AuthorizationRole&\BackedEnum)|null, never> */
    protected function appRole(): Attribute
    {
        return Attribute::get(fn (): ?AuthorizationRole => resolve(AuthorizationManager::class)->primaryRole($this));
    }

    /** @return Attribute<bool, never> */
    protected function isPlatformAdmin(): Attribute
    {
        return Attribute::get(fn (): bool => resolve(AuthorizationManager::class)->isSuperAdmin($this));
    }
}
