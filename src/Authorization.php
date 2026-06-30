<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization;

use AlexPavliukov\Authorization\Contracts\AuthorizationRole;
use AlexPavliukov\Authorization\Contracts\BypassStrategy;
use AlexPavliukov\Authorization\Contracts\TeamResolver;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void useRoleEnum(string $roleEnum)
 * @method static string roleEnum()
 * @method static void authorizableModels(string[] $models)
 * @method static string[] models()
 * @method static BackedEnum[] superAdminRoles()
 * @method static void resolveTeamsUsing(string|TeamResolver|Closure $resolver)
 * @method static TeamResolver teamResolver()
 * @method static mixed withTeam(int|string|null $teamId, callable $callback)
 * @method static bool userHasGlobalRole(Authenticatable $user, BackedEnum|string $role)
 * @method static bool userHasRoleInTeam(Authenticatable $user, BackedEnum|string $role, int|string $teamId)
 * @method static bool userHasRole(Authenticatable $user, BackedEnum|string $role)
 * @method static list<string> userRolesInTeam(Authenticatable $user, int|string|null $teamId)
 * @method static (AuthorizationRole&BackedEnum)|null primaryRole(Authenticatable $user)
 * @method static void forgetUserRoles(Authenticatable $user)
 * @method static void resolveTenantUsing(Closure $resolver)
 * @method static void tenantColumn(string $column)
 * @method static void systemAbilities(string $enum)
 * @method static void bypassUsing(string|BypassStrategy $strategy)
 *
 * @see AuthorizationManager
 */
final class Authorization extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuthorizationManager::class;
    }
}
