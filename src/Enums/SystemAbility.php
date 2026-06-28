<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Enums;

/**
 * System abilities are registered as standalone Gate::define() calls with no
 * model attached. They are intentionally separate from the resource Ability
 * enum so they never generate model permissions via HasPolicy.
 *
 * The consumer defines each gate in its published AuthorizationServiceProvider
 * (default: deny-all), checks it with Gate::authorize()/@can, and may widen it
 * per app. Super-admins are granted automatically via the Gate::before bypass.
 */
enum SystemAbility: string
{
    /**
     * Grants entry to the platform administration area (the back-office for
     * managing the platform itself), not an action on any model. Gate it on
     * admin routes/menus; by default only super-admins pass.
     */
    case ACCESS_PLATFORM_ADMIN = 'accessPlatformAdmin';
}
