<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Database;

use AlexPavliukov\Authorization\Support\ModelHasRolesQuery;
use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Schema;

/**
 * Re-derive the owner role rows from the tenants' `owner_key` column: the owner
 * holds the owner role in the tenant's team, nobody else does. Idempotent; run on
 * every deploy via AuthorizationSeeder to undo drift from manual DB edits.
 */
final readonly class OwnershipRepair
{
    public function __construct(private CurrentTenant $tenancy, private ModelHasRolesQuery $roles) {}

    /**
     * @return array{created: int, removed: int}|string the counts, or why the repair was skipped
     */
    public function apply(): array|string
    {
        $ownerKey = $this->tenancy->ownerKey();
        $ownerRole = $this->tenancy->ownerRole();

        if ($ownerKey === null || $ownerRole === null) {
            return 'Tenant ownership is off (authorization.tenancy.owner_role is not set).';
        }

        $tenant = new ($this->tenancy->modelClass());

        if (! Schema::connection($tenant->getConnectionName())->hasColumn($tenant->getTable(), $ownerKey)) {
            return "Column [{$tenant->getTable()}.{$ownerKey}] does not exist; ownership repair skipped.";
        }

        return $this->roles->syncOwners($tenant, $ownerKey, $ownerRole);
    }

    /** apply(), as one line for the console and the log. */
    public function report(): string
    {
        $result = $this->apply();

        return is_string($result) ? $result : sprintf('Owner roles repaired: %d created, %d removed.', $result['created'], $result['removed']);
    }
}
