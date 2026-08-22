<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use AlexPavliukov\Authorization\TenantScopedPolicy;

final readonly class PostPolicy extends TenantScopedPolicy
{
    protected function getModelClass(): string
    {
        return Post::class;
    }
}
