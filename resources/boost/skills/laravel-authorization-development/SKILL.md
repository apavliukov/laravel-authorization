---
name: laravel-authorization-development
description: >
  Work with the apavliukov/laravel-authorization package: policies, role enums, abilities,
  permission seeding/sync, super-admin bypass, tenant scoping, and Spatie teams integration.
  Use when adding a model policy, a role, a system or custom ability, wiring the
  AuthorizationServiceProvider, debugging why a can()/Gate check passes or fails, or
  testing authorization. The app's own role cases, tenancy choice, and
  deviations live in the project's CLAUDE.md manifest — read it alongside this skill.
---

# Laravel Authorization Package

Namespace `AlexPavliukov\Authorization\`. The package owns the core: super-admin bypass,
abstract policies, permission registry, seeding, tenancy, testing helpers. The app owns only
its `Role` enum, concrete policies, `SystemAbility` enum, and a thin declarations provider.
Never re-implement core in the app — extend it.

## How It Works

Two layers:

**Layer 1 — Gate/Policy.** The provider registers a single `Gate::before`: users holding
a role-enum case where `isSuperAdmin()` is `true` (under teams: as a GLOBAL assignment)
short-circuit to `true`. Everyone else → `null` (fall through). The bypass **never returns
`false`** — that would veto a legitimately-granted permission.

**Layer 2 — Spatie permissions.** Policy methods call
`userCan($user, Ability::UPDATE, $model)` → builds a permission string (`"update posts"`)
→ Spatie checks the DB.

Resolution order for any `can()` / `cannot()`:

1. Package `Gate::before` (bypass) — super-admin `true`, others `null`
2. Spatie's `Gate::before` — DB permission found → `true`, else `null`
3. Model passed → policy method
4. No model → `Gate::define()` registration

## App Wiring — `AuthorizationServiceProvider`

The package auto-registers (discovery) the bypass;
middleware is wired by the app in `bootstrap/app.php`. The app provider only declares specifics:

```php
public function boot(): void
{
    Authorization::useRoleEnum(Role::class);

    Authorization::authorizableModels([
        User::class,
    ]);
}
```

## Abilities

CRUD abilities ship in the package — `AlexPavliukov\Authorization\Enums\Ability`, values
camelCase to match policy method names exactly: `viewAny`, `view`, `create`, `update`,
`delete`, `restore`, `forceDelete`.

**System abilities are app-owned** (standalone gates, never stored as permissions):

```php
// app/Enums/Policies/SystemAbility.php
enum SystemAbility: string
{
    case ACCESS_PLATFORM_ADMIN = 'accessPlatformAdmin';
}
```

Model-specific **custom abilities** are also app-owned, in `app/Enums/Policies/`.

## Permission Names

`PermissionRegistry::nameFromAbility(BackedEnum $ability, Model|string $model)`:

```
Ability::VIEW_ANY + User          →  "view any users"
UserAbility::IMPERSONATE + User   →  "impersonate users"
```

## Adding a New Model

1. `app/Policies/PostPolicy.php` extending `AbstractPolicy` with `getModelClass()`.
   Auto-discovered by naming convention.
2. Add `AlexPavliukov\Authorization\Concerns\HasPolicy` to the model — provides
   `getBasicAbilities()` / `getCustomAbilities()` for seeding.
3. No SoftDeletes → override `getBasicAbilities()` filtering out `RESTORE`/`FORCE_DELETE`.
4. Register in `Authorization::authorizableModels([...])`.
5. Re-run `AuthorizationSeeder`.

## Ownership / Tenancy Fencing

`AbstractPolicy` exposes an `ownsModel()` hook (default `true`). Model-bound methods are
`ownsModel() && userCan()`; `viewAny`/`create` check the permission only. Override the
hook — **don't** re-implement CRUD methods for ownership:

```php
protected function ownsModel(Authenticatable $user, Model $model): bool
{
    return $model->company_id === $user->company?->id;
}
```

CRUD methods are intentionally not `final` — a policy needing "manage-across-org OR own"
overrides a method and calls `parent::view(...)`. Prefer the hook.

For plain attribute tenancy, extend `TenantScopedPolicy` instead of hand-writing
`ownsModel()`: it compares `Tenancy::id()` with the model's `tenancy.foreign_key`
column; override `tenantKey(Model $model)` when the tenant is reached through a relation.

## Adding a New Role

Role enum implements `AlexPavliukov\Authorization\Contracts\AuthorizationRole`:
`label()`, `isSuperAdmin()`, `permissions()`. Keep `match($this)` arms exhaustive (no
`default`) — forgetting a case throws `UnhandledMatchError` at seed time. **Add the new
case to `byPriority()`** — `Authorization::primaryRole()` / `$user->app_role` return the
first case there the user holds; declaration order is ignored, and sync throws a
`LogicException` if a case is missing or repeated. The order is a product decision, not
a permission count (oohire: `CANDIDATE` before `ORGANIZATION_ADMIN`, so an admin who is
also a candidate lands in the candidate area).

Build permission sets with the `Permissions` builder, never hand-written strings:

```php
self::MANAGER => Permissions::make()
    ->for(Post::class)->only(Ability::VIEW_ANY, Ability::VIEW)
    ->forAll(Comment::class)     // every ability of the model
    ->all(),
```

## Adding a System Ability

Add the case, then define its gate in the provider — deny-by-default, so only the
super-admin bypass grants it; no seeding. If a non-super role must hold one, the gate
closure says so:

```php
Gate::define(SystemAbility::ACCESS_PLATFORM_ADMIN->value, static fn (): bool => false);
Gate::define(SystemAbility::ACCESS_MANAGER_AREA->value, static fn (User $user): bool => $user->hasRole(Role::MANAGER));
```

## Custom Abilities

Enum in `app/Enums/Policies/` (e.g. `UserAbility::IMPERSONATE`), declared via the model's
`getCustomAbilities()`, policy method named after the enum value. The registry merges
basic + custom automatically when seeding.

## Bypass

One `Gate::before`, not pluggable. **Principle:** a super-admin has the *right* to do
everything; real "can't"s (an org can't lose its last owner) are **business invariants**
enforced in the Action/domain layer, not authorization. The bypass is model-less by
design — model/team-scoped bypasses are impossible; with teams on, scoping belongs to
team-scoped roles + `ownsModel()`, never a second bypass.

Third-party gates follow the bypass pattern too:
`Gate::define('viewHorizon', static fn (): bool => false)` — the bypass grants super-admins.
Role-check booleans (`$user->is_admin` and similar) are routing/UX helpers — **never**
authorization; always go through the Gate.

## Seeding & Sync — the sharpest edge

`AuthorizationSeeder` → `PermissionSync::apply()`: flush Spatie cache, `firstOrCreate`
permissions from the registry, `firstOrCreate` roles, then `syncPermissions()`.

**`syncPermissions()` is a set operation, not a merge.** A role whose `permissions()`
returns `[]` has every permission **detached** on the next sync:

> The `Role` enum is the single source of truth. Any grant made outside it — by hand in
> the DB, in a data seeder, through an admin UI — is silently revoked on the next sync.

Super-admin roles safely return `[]` (the bypass short-circuits first). For a non-super
role, `[]` is an active deny-all — declare its grants in the enum, never in the DB.

Permissions the registry no longer declares are left in place.

## Tenancy

Config-driven (`config/authorization.php`, publish tag `authorization-config`):
`tenancy.model` switches it on; the tenant is the Spatie team. **Membership = a role
row in `model_has_roles` with `team_id`, nothing else** — never a tenant column on
`users` or a pivot. `TenantResolver`: `session_key` (validated against membership;
global super-admins may enter any existing tenant) → the user's sole tenant → `null`
(several tenants, no choice ⇒ `EnsureTenantSelected`). Everything downstream consumes
`CurrentTenant` (`Tenancy` facade).

```php
use BelongsToTenant;                                   // scope + autofill + tenant()
protected static function tenantRelationPath(): ?string { return 'post'; }  // transitive owner

Tenancy::id() / get() / set($tenant) / forget()
Tenancy::withoutTenant(fn () => …)                     // the ONLY bypass; no withoutGlobalScope at call sites
Tenancy::forTenant($other, fn () => …)
```

Scope is fail-closed: no tenant + no bypass ⇒ no rows, `MissingTenantContextException`
when `tenancy.strict`. Cross-tenant bindings 404. Sessionless code (jobs, seeders,
commands) must wrap in `withoutTenant()` or `forTenant()`; queued jobs inherit the
dispatcher's tenant/bypass via `Context`.

Wire in `bootstrap/app.php`: `$middleware->web(append: [SetCurrentTenant::class])` — the
package registers no group middleware itself (a provider push is dropped when the app
configures its groups). Livewire persistent
middleware is registered automatically when Livewire is present.

**Tenant-level roles are assigned inside the tenant** (`TenantMembership::add`), also
when each user has one tenant — a global (`team_id NULL`) role is invisible while a
tenant is active. Only super-admin roles are global (`TenantMembership::assignGlobal`).

Middleware: `SetCurrentTenant` (priority before `SubstituteBindings`),
`EnsureTenantSelected` (403 / `missing_tenant_redirect`), `EnsurePlatformArea`
(`accessPlatformAdmin` deny-all gate + request-wide bypass). Platform admin is a
**global** role; the bypass reads the global assignment regardless of the active team.

Membership goes through `Tenancy\TenantMembership` (`add / changeRole / remove /
roleFor / isMember / assignGlobal / removeGlobal / tenantIdsOf`); `tenancy.memberships
=> 'single'` refuses a second tenant (`MembershipPolicyException`).

Ownership (`tenancy.owner_role` set): the tenant's `owner_key` column (default
`owner_id`, app migration NOT NULL + FK users) is the only record; the owner's role row
is derived — `transferOwnership($tenant, $newOwner, $demoteTo = null)` writes both, also
right after creating a tenant. `ownerOf / isOwner` and `HasMembers::owner()` read the
column; it never grants access. Editing the owner's membership, granting the owner role,
or deleting an owner throws `OwnershipException`. `AuthorizationSeeder` re-derives
owner rows. Archive tenants (soft delete);
never hard-delete. Relations:
`Tenancy\Concerns\HasMembers` on the tenant (`members()`), `HasTenantMemberships` on
the user (`tenants()`), both morph relations through `model_has_roles`. Identity:
`Concerns\HasAppRole` on the user — `$user->app_role` (highest role held anywhere),
`$user->is_platform_admin` (global super-admin); works without tenancy too. The
`accessPlatformAdmin` gate is always registered. Which UI area a request is in is the
app's routing concern, not the package's.

Tests: `Testing\InteractsWithTenancy` — `actingAsInTenant($user, $tenant, $role)`,
`withoutTenant()`, `forTenant()`, `assertFailsClosed(Model::class)`.

## Teams (Multi-Tenancy)

Single source of truth: `config/permission.php` → `'teams' => true`. `SetCurrentTenant`
sets the permissions team per request; teams without tenancy are the app's own
middleware (`setPermissionsTeamId()`). The package registers no group middleware.

Spatie's `hasRole()` answers against the *active* team. Team-independent reads:

```php
Authorization::userHasGlobalRole($user, Role::ADMIN);           // team_id IS NULL only
Authorization::userHasRoleInTeam($user, Role::MEMBER, $teamId);
Authorization::userHasRole($user, Role::MEMBER);                // any team, or global
Authorization::userRolesInTeam($user, $teamId);
Authorization::withTeam($teamId, fn () => $user->can(Ability::UPDATE, $post));  // restored even on throw
```

`Authorization::primaryRole($user)` — first role in the enum's `byPriority()` the user holds,
team-agnostic; suits landing/redirect decisions.

### Request memoization

Team-aware role reads are memoized per request. Actions that assign or revoke roles
**must** call `Authorization::forgetUserRoles($user)` (`TenantMembership` does), or later
reads in the same request return stale answers. `userCan()` is not memoized.

## Calling Authorization

```php
$this->authorize(Ability::UPDATE, $user);          // controller
Gate::authorize(Ability::UPDATE, $user);           // Livewire / elsewhere
@can(Ability::UPDATE, $user) ... @endcan            {{-- Blade --}}
```

Always enums — never magic ability strings, never `hasRole()` for an authorization
decision.

## Testing

Mix in `AlexPavliukov\Authorization\Testing\InteractsWithAuthorization`:

```php
$this->assignRoleInTeam($user, Role::MEMBER, $teamId);   // null = global
$this->withPermissionsTeam($teamId, fn () => /* assert */);
$this->roleModelId(Role::MEMBER);                         // throws if role not seeded
```

Resolve policies from the container in tests — `new SomePolicy(...)` breaks whenever the
package-injected constructor dependencies change.
