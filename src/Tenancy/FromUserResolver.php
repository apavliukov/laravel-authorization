<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use AlexPavliukov\Authorization\Contracts\TenantResolver;
use AlexPavliukov\Authorization\Support\ScalarKey;
use Illuminate\Http\Request;

/** One tenant per user, read from `authorization.tenancy.user_column`. */
final class FromUserResolver implements TenantResolver
{
    public function resolve(Request $request): int|string|null
    {
        $column = config('authorization.tenancy.user_column', 'organization_id');

        return ScalarKey::normalize($request->user()?->getAttribute(is_string($column) ? $column : 'organization_id'));
    }
}
