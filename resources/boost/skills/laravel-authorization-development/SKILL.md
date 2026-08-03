---
name: laravel-authorization-development
description: >
  Work with the apavliukov/laravel-authorization package: policies, role enums, abilities,
  permission seeding/sync, super-admin bypass, tenant scoping, and Spatie teams integration.
  Use when adding a model policy, a role, a system or custom ability, wiring the
  AuthorizationServiceProvider, debugging why a can()/Gate check passes or fails, running
  authorization:sync, or testing authorization. The app's own role cases, tenancy choice, and
  deviations live in the project's CLAUDE.md manifest — read it alongside this skill.
---

# Laravel Authorization Package

Namespace `AlexPavliukov\Authorization\`. The package owns the core: bypass gate, abstract
policies, permission registry, sync, teams middleware, testing helpers. The app owns only
its `Role` enum, concrete policies, `SystemAbility` enum, and a thin declarations provider.
Never re-implement core in the app — extend it.

## How It Works

Two layers:

**Layer 1 — Gate/Policy.** The package registers a single `Gate::before` via `BypassGate`,
backed by a pluggable `BypassStrategy`. The default `RoleBypass` resolves all role-enum
cases where `isSuperAdmin()` is `true` and short-circuits to `true` for users holding any
of them. Non-matching users → `null` (fall through). The bypass **never returns `false`**
— that would veto a legitimately-granted permission.

**Layer 2 — Spatie permissions.** Policy methods call
`userCan($user, Ability::UPDATE, $model)` → builds a permission string (`"update posts"`)
→ Spatie checks the DB.

Resolution order for any `can()` / `cannot()`:

1. Package `Gate::before` (bypass) — super-admin `true`, others `null`
2. Spatie's `Gate::before` — DB permission found → `true`, else `null`
3. Model passed → policy method
4. No model → `Gate::define()` registration

## App Wiring — `AuthorizationServiceProvider`

The package auto-registers (discovery) the bypass, the teams middleware (behind Spatie's
flag), and `make:authorization-policy`. The app provider only declares specifics:

```php
public function boot(): void
{
    Authorization::useRoleEnum(Role::class);

    Authorization::authorizableModels([
        User::class,
    ]);
}
```

Optional declarations, only when the app needs them:

```php
Authorization::systemAbilities(SystemAbility::class);          // deny-by-default gate per case
Authorization::resolveTenantUsing(fn (User $user) => $user->company_id);
Authorization::tenantColumn('company_id');                      // default: 'tenant_id'
Authorization::resolveTeamsUsing(fn (Request $request) => $request->user()?->company_id);
Authorization::bypassUsing(NoBypass::class);
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

1. `artisan make:authorization-policy Post` → policy extending `AbstractPolicy` with
   `getModelClass()`. Auto-discovered by naming convention.
2. Add `AlexPavliukov\Authorization\Concerns\HasPolicy` to the model — provides
   `getBasicAbilities()` / `getCustomAbilities()` for seeding.
3. No SoftDeletes → override `getBasicAbilities()` filtering out `RESTORE`/`FORCE_DELETE`.
4. Register in `Authorization::authorizableModels([...])`.
5. Re-run the authorization sync (seeder or `artisan authorization:sync`).

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
`ownsModel()`: declare `resolveTenantUsing()` + `tenantColumn()` once in the provider;
override `tenantKey(Model $model)` when the tenant is reached through a relation.
`currentTenant()` throws if `resolveTenantUsing()` was never declared.

## Adding a New Role

Role enum implements `AlexPavliukov\Authorization\Contracts\AuthorizationRole`:
`label()`, `isSuperAdmin()`, `permissions()`. Keep `match($this)` arms exhaustive (no
`default`) — forgetting a case throws `UnhandledMatchError` at seed time. **Order cases
from most to least privileged** — `Authorization::primaryRole()` returns the first case
the user holds.

Build permission sets with the `Permissions` builder, never hand-written strings:

```php
self::MANAGER => Permissions::make()
    ->for(Post::class)->only(Ability::VIEW_ANY, Ability::VIEW)
    ->forAll(Comment::class)     // every ability of the model
    ->all(),
```

## Adding a System Ability

Add the case, then register the whole enum: `Authorization::systemAbilities(SystemAbility::class)`
— deny-by-default gate per case, bypass grants super-admins, no seeding. **Bypass-only
gates.** If a non-super role must hold one, define that gate yourself instead —
`systemAbilities()` would overwrite it with a deny-all:

```php
Gate::define(SystemAbility::ACCESS_MANAGER_AREA, static fn (User $user): bool => $user->hasRole(Role::MANAGER));
```

## Custom Abilities

Enum in `app/Enums/Policies/` (e.g. `UserAbility::IMPERSONATE`), declared via the model's
`getCustomAbilities()`, policy method named after the enum value. The registry merges
basic + custom automatically when seeding.

## Pluggable Bypass

```php
Authorization::bypassUsing(NoBypass::class);   // no god-mode at all
Authorization::bypassUsing(new RoleBypass($manager, protected: [Ability::FORCE_DELETE]));
```

**Principle:** a super-admin has the *right* to do everything; real "can't"s (an org can't
lose its last owner) are **business invariants** enforced in the Action/domain layer, not
authorization. `protected` is only for genuine authorization carve-outs (separation of
duties, break-glass). The bypass contract is deliberately model-less — model/team-scoped
bypasses are impossible by design; with teams on, scoping belongs to team-scoped roles +
`ownsModel()`, never a second bypass.

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

Pruning is opt-in: `apply(prune: true)` deletes permissions no longer declared; the
default leaves stale ones in place (reported).

```bash
artisan authorization:sync --dry-run   # print the plan, change nothing — run before prod syncs
artisan authorization:sync             # create + grant/revoke
artisan authorization:sync --prune     # also delete stale permissions
```

## Teams (Multi-Tenancy)

Single source of truth: `config/permission.php` → `'teams' => true`. The package then
pushes `SetPermissionsTeam` onto `web` **only**; for API routes push it yourself in
`bootstrap/app.php`: `$middleware->appendToGroup('api', SetPermissionsTeam::class)`.
It resolves the team id via `Authorization::teamResolver()` (default reads the user's
team foreign key; override with a `TeamResolver` class or closure).

Spatie's `hasRole()` answers against the *active* team. Team-independent reads:

```php
Authorization::userHasGlobalRole($user, Role::ADMIN);           // team_id IS NULL only
Authorization::userHasRoleInTeam($user, Role::MEMBER, $teamId);
Authorization::userHasRole($user, Role::MEMBER);                // any team, or global
Authorization::userRolesInTeam($user, $teamId);
Authorization::withTeam($teamId, fn () => $user->can(Ability::UPDATE, $post));  // restored even on throw
```

`Authorization::primaryRole($user)` — highest-priority role by enum declaration order,
team-agnostic; suits landing/redirect decisions.

### Request memoization

Role reads and `userCan()` verdicts are memoized per request (user, permission, team).
Actions that assign or revoke roles **must** call `Authorization::forgetUserRoles($user)`
(or `forgetUserPermissions()`), or later checks in the same request return stale answers.

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
