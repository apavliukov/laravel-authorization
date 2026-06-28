<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Concerns;

use AlexPavliukov\Authorization\Enums\Ability;
use BackedEnum;

trait HasPolicy
{
    /**
     * Returns the standard CRUD abilities that generate permissions for this model.
     * Override to restrict which abilities apply — e.g. exclude RESTORE and
     * FORCE_DELETE on models that don't use SoftDeletes.
     *
     * @return array<int, BackedEnum>
     */
    public static function getBasicAbilities(): array
    {
        return Ability::cases();
    }

    /**
     * Returns model-specific ability enum cases beyond the standard CRUD set.
     * Override in the model to declare custom abilities:
     *
     *   public static function getCustomAbilities(): array
     *   {
     *       return UserAbility::cases();
     *   }
     *
     * @return array<int, BackedEnum>
     */
    public static function getCustomAbilities(): array
    {
        return [];
    }
}
