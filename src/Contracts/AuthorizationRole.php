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
}
