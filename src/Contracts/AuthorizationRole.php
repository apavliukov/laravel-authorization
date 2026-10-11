<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Contracts;

interface AuthorizationRole
{
    /** Whether holders of this role bypass every gate check via Gate::before. */
    public function isSuperAdmin(): bool;

    /**
     * Permission names granted to this role, consumed by the seeder.
     *
     * @return array<int, string>
     */
    public function permissions(): array;

    /**
     * Every case, highest priority first. primaryRole() / $user->app_role return the
     * first role here the user holds anywhere; order is a product decision, not a
     * permission count.
     *
     * @return list<static>
     */
    public static function byPriority(): array;
}
