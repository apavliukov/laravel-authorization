<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization;

use AlexPavliukov\Authorization\Contracts\BypassStrategy;
use AlexPavliukov\Authorization\Contracts\TeamResolver;
use BackedEnum;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void useRoleEnum(string $roleEnum)
 * @method static string roleEnum()
 * @method static void authorizableModels(string[] $models)
 * @method static string[] models()
 * @method static BackedEnum[] superAdminRoles()
 * @method static void resolveTeamsUsing(string|TeamResolver $resolver)
 * @method static TeamResolver teamResolver()
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
