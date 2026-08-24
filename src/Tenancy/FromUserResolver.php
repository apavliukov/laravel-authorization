<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use AlexPavliukov\Authorization\Contracts\TenantResolver;
use AlexPavliukov\Authorization\Support\ScalarKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** One tenant per user, read from `authorization.tenancy.user_column`. */
final class FromUserResolver implements TenantResolver
{
    public function resolve(Request $request): int|string|null
    {
        $column = config('authorization.tenancy.user_column', 'organization_id');
        $user = $request->user();

        if (! $user instanceof Model) {
            return null;
        }

        // Not getAttribute(): under Model::shouldBeStrict() it throws for a column the
        // instance never loaded (a factory-built user in a test, a partial select).
        return ScalarKey::normalize($user->getAttributes()[is_string($column) ? $column : 'organization_id'] ?? null);
    }
}
