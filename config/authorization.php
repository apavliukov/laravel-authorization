<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | Leave `model` null for apps without tenants. When set, the tenant is also
    | the Spatie permissions team — `permission.teams` must be true and
    | `permission.models.team` should point at the same model.
    |
    */

    'tenancy' => [
        // App\Models\Organization::class
        'model' => null,

        // Column on tenant-owned tables (and on users for the `user` resolver).
        'foreign_key' => 'organization_id',

        // `user`: one tenant per user, read from `user_column`.
        // `session`: user belongs to many tenants; the active one is kept in `session_key`.
        'resolver' => 'user',
        'user_column' => 'organization_id',
        'session_key' => 'current_organization_id',

        // Throw MissingTenantContextException when a tenant-owned query runs with
        // no tenant and no bypass. Off ⇒ the query silently yields nothing.
        'strict' => env('APP_ENV') !== 'production',

        // Route name EnsureTenantSelected redirects to when no tenant is active.
        'missing_tenant_redirect' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Areas
    |--------------------------------------------------------------------------
    |
    | area name => route-name pattern (Str::is wildcards). CurrentArea resolves
    | the area of the current route from these; a session view mode is the
    | fallback. Order matters: the first match wins, the first entry is the
    | default.
    |
    */

    'areas' => [
        'platform' => '*platform.*',
        'organization' => '*organization.*',
        'member' => '*member.*',
    ],

];
