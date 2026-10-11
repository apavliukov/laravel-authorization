<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Console;

use AlexPavliukov\Authorization\Database\OwnershipRepair;
use Illuminate\Console\Command;

final class RepairOwnershipCommand extends Command
{
    protected $signature = 'authorization:repair-ownership';

    protected $description = "Re-derive every tenant owner's role row from the tenant's owner column";

    public function handle(OwnershipRepair $repair): int
    {
        $this->info($repair->report());

        return self::SUCCESS;
    }
}
