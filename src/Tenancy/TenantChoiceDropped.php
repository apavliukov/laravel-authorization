<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use Illuminate\Contracts\Auth\Authenticatable;

/** The resolver dropped the session's tenant choice: the user may no longer enter it (removed, deleted, not enterable). */
final readonly class TenantChoiceDropped
{
    public function __construct(public Authenticatable $user, public int|string $tenantId) {}
}
