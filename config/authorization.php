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

        // Local scope on the tenant model a tenant must pass to be entered (session
        // choice or sole-tenant fallback), super-admins included. A tenant falling
        // out of it is dropped from the session on the next request. null ⇒ any
        // tenant row that exists.
        'enterable_scope' => null,   // 'active'

        // Several enterable tenants and no session choice ⇒ enter the one the user
        // owns (if exactly one) instead of none. Needs ownership on (owner_role).
        'fallback_to_owned' => false,

        // Throw MissingTenantContextException when a tenant-owned query runs with
        // no tenant and no bypass. Off ⇒ the query silently yields nothing.
        'strict' => env('APP_ENV') !== 'production',

        // Route name EnsureTenantSelected redirects to when no tenant is active.
        'missing_tenant_redirect' => null,

        // Column on the tenant table naming the owning user — the only record of
        // ownership (the app's migration: NOT NULL, FK users, restrictOnDelete).
        'owner_key' => 'owner_id',

        // Role the owner holds in the tenant's team, written only by the package
        // together with owner_key. A role enum case or its value. null ⇒ ownership
        // is off: no guards, no repair, no delete listener.
        'owner_role' => null,   // App\Enums\Role::OWNER

        // 'multiple': a user may hold roles in several tenants. 'single':
        // add() / changeRole() / transferOwnership() refuse a second tenant.
        'memberships' => 'multiple',
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
