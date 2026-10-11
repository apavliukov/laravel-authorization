<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Database;

use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Generic seeder that syncs permissions (from the authorizable models) and roles
 * (from each role enum case's permissions()), then repairs the tenant owners'
 * role rows when ownership is on. Consumers call it from their own seeder:
 * $this->call([AuthorizationSeeder::class]).
 */
final class AuthorizationSeeder extends Seeder
{
    public function __construct(
        private readonly PermissionSync $sync,
        private readonly OwnershipRepair $ownership,
        private readonly CurrentTenant $tenancy,
    ) {}

    public function run(): void
    {
        $this->sync->apply();

        if ($this->tenancy->ownerKey() === null) {
            return;
        }

        $message = $this->ownership->report();
        Log::info($message);

        // Laravel types it non-null, but it is unset when the seeder runs outside db:seed.
        if ($this->command instanceof Command) {
            $this->command->info($message);
        }
    }
}
