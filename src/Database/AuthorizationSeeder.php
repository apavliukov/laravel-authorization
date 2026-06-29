<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Database;

use Illuminate\Database\Seeder;

/**
 * Generic seeder that syncs permissions (from the authorizable models) and roles
 * (from each role enum case's permissions()). Consumers call it from their own
 * seeder: $this->call([AuthorizationSeeder::class]).
 */
final class AuthorizationSeeder extends Seeder
{
    public function __construct(private readonly PermissionSync $sync) {}

    public function run(): void
    {
        $this->sync->apply();
    }
}
