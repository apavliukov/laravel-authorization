<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use RuntimeException;

final class MissingTenantContextException extends RuntimeException
{
    public static function forModel(string $modelClass): self
    {
        return new self("[{$modelClass}] was queried or created with no current tenant. Set one, wrap the call in Tenancy::withoutTenant() / forTenant(), or pass the tenant key explicitly.");
    }
}
