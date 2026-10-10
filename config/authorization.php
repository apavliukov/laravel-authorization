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

        // Column on tenant-owned tables.
        'foreign_key' => 'organization_id',

        // Membership = a role in the tenant's team (model_has_roles.team_id). The
        // active tenant is the session choice (validated against membership),
        // else the user's sole team, else none.
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
