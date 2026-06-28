<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Enums;

/**
 * Standard resource abilities — 1:1 with Laravel policy method names.
 *
 * Values are camelCase so Gate routes them directly to the matching policy method:
 * `Gate::authorize(Ability::VIEW_ANY, User::class)` → `UserPolicy::viewAny()`.
 *
 * PermissionRegistry::nameFromAbility() converts the value to a space-separated DB
 * permission string: VIEW_ANY + users → "view any users".
 *
 * System abilities (singleton Gate checks, no model) live in SystemAbility.
 * Model-specific abilities (e.g. UserAbility) extend this set via HasPolicy::getCustomAbilities().
 */
enum Ability: string
{
    case VIEW_ANY = 'viewAny';
    case VIEW = 'view';
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case RESTORE = 'restore';
    case FORCE_DELETE = 'forceDelete';
}
