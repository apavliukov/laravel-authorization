# Adopting `apavliukov/laravel-authorization`

A guide for an agent (or developer) refactoring an existing Laravel app's
authorization layer onto this package. It describes the package model, its public
API, the **design decisions and invariants** to refactor *toward*, the migration
steps, reusable recipes, anti-patterns to remove, and the **decisions you must
resolve against the specific project** (don't guess these).

> This package is an opinionated, thin layer over `spatie/laravel-permission`.
> It owns the generic core; the app keeps only what is app-specific. The goal of
> an adoption is to **delete the app's local generic auth core and converge on the
> package**, not to bend the package to the app's existing (possibly divergent)
> shape.

- **Composer:** `apavliukov/laravel-authorization` (`^0.6`)
- **Namespace:** `AlexPavliukov\Authorization\`
- **Requires:** PHP `^8.4`, Laravel `^13`, `spatie/laravel-permission ^8.0`

---

## 0. Before you start — resolve these with the project/human

These are **not inferable from the package**. Read the app's current auth code and
decide each one explicitly before refactoring:

1. **Is there multi-tenancy?** If so, what is the tenant keyed by — a column
   (`company_id`, `team_id`), a relation walk, or a pivot table?
2. **Spatie teams: on or off?** Teams = `config('permission.teams')`. Turn it on
   only if you need **team-scoped role/permission assignments** (the same user
   holding different permissions per tenant). Otherwise keep it off and scope in
   policies. **If you also have platform-level (global) roles under teams** (a role
   effective regardless of tenant, e.g. a platform admin), they are assigned with a
   `NULL` pivot `team_id` — but Spatie's stock teams migration makes
   `model_has_roles.team_id` `NOT NULL` and part of the primary key. To store global
   assignments, make that column **nullable** and replace the primary key with a
   unique index that includes `team_id`.
3. **Are roles stored anywhere other than Spatie?** (e.g. a `*_user.role_id`
   pivot.) If yes, decide: **migrate them into Spatie roles/teams** so they fit the
   package, or keep a custom resolver *outside* the package (the package then only
   provides `AbstractPolicy` + `ownsModel()` and you keep your own `Gate::before`).
   Prefer migrating — it's the whole point of unifying.
4. **Which role(s) are super-admin?** (`AuthorizationRole::isSuperAdmin()` → true.)
   **Under teams**, the default `RoleBypass` is team-scoped: a super-admin assigned
   globally (`team_id IS NULL`) bypasses only when no team is active (platform
   context); inside a team they get their team-scoped rights, not god-mode. Decide
   whether that "god-mode only in platform context" behaviour is what you want.
5. **Bypass policy:** default `RoleBypass` (super-admins bypass everything)? Any
   abilities that must always go through policies even for super-admins
   (`protected`)? Or no god-mode at all (`NoBypass`)?
6. **System abilities** the app needs (model-less gates, e.g. "access admin area").
7. **Custom (non-CRUD) abilities** per model (e.g. `impersonate`, `publish`).

---

## 1. The model in one screen

```
Role enum (app)  implements AuthorizationRole
    isSuperAdmin(): bool        → drives the Gate::before bypass
    permissions(): string[]      → what the seeder grants this role

User (app)  uses Spatie HasRoles + package HasPolicy

Ability (package enum)  the 7 CRUD verbs (camelCase = policy method names)
SystemAbility (APP enum)  model-less gates — the package ships none

AuthorizationManager (singleton)  holds: role enum class + authorizable models
Authorization (facade)            useRoleEnum / authorizableModels / bypassUsing / resolveTeamsUsing
                                  withTeam / userHasGlobalRole / userHasRoleInTeam / userHasRole / userRolesInTeam / forgetUserRoles

PermissionRegistry   ability + model → "{ability words} {table}"  (e.g. "view any users")
AbstractPolicy       view/update/delete/restore/forceDelete = ownsModel() && userCan()
                     viewAny/create = userCan()    ownsModel() default true (override to scope)
BypassGate + BypassStrategy   one Gate::before; RoleBypass (default) | NoBypass | your own
PermissionSync + AuthorizationSeeder   plan()/apply(prune) + idempotent seeding; authorization:sync command
Teams (optional)     DefaultTeamResolver | CallbackTeamResolver + SetPermissionsTeam middleware (only when teams on)
Team-aware reads     userHas{Global,InTeam,}Role / userRolesInTeam (memoized, request-scoped) + HasTeamAwareRoles query scopes
Testing\InteractsWithAuthorization   test primitives (assignRoleInTeam / withPermissionsTeam / roleModelId / resetPermissionsTeam)
make:authorization-policy {Model}    scaffolds a policy
```

Resolution order for any `can()` / `authorize()` / `@can`:

1. `Gate::before` → `true` for a bypassing user (super-admin), else `null` (fall through). **Never `false`.**
2. Spatie permission check (the policy's `userCan()` builds the permission string).
3. Policy method (for model-bound abilities) / `Gate::define()` (for system abilities).

---

## 2. Public API surface (what the app touches)

| Symbol | Kind | Use |
|---|---|---|
| `Authorization` (facade) | facade | `useRoleEnum()`, `authorizableModels()`, `bypassUsing()`, `resolveTeamsUsing()` |
| `Contracts\AuthorizationRole` | interface | implemented by the app's role enum: `isSuperAdmin(): bool`, `permissions(): string[]` |
| `Contracts\BypassStrategy` | interface | `shouldBypass(Authenticatable $user, string $ability): bool` |
| `Contracts\TeamResolver` | interface | `resolve(Request): int\|string\|null` |
| `AbstractPolicy` | abstract | extend per model; implement `getModelClass()`; override `ownsModel()` for tenancy |
| `Concerns\HasPolicy` | trait | on each policy-protected model; `getBasicAbilities()` / `getCustomAbilities()` |
| `Enums\Ability` | enum | the 7 CRUD abilities |
| `PermissionRegistry` | service | `nameFromAbility()`, `allPermissions()` (rarely called directly) |
| `Support\RoleBypass` / `NoBypass` | strategies | default / no-god-mode bypass |
| `Database\AuthorizationSeeder` | seeder | call from the app's seeder |
| `Teams\DefaultTeamResolver` / `CallbackTeamResolver` / `SetPermissionsTeam` | teams | used only when teams are on; `CallbackTeamResolver` resolves the team from a closure (session/request context) |
| team-aware reads (facade) | facade | `userHasGlobalRole()`, `userHasRoleInTeam()`, `userHasRole()` (any team), `userRolesInTeam()`, `forgetUserRoles()` — memoized per request |
| `Concerns\HasTeamAwareRoles` | trait | query scopes `whereHasGlobalRole` / `whereHasRoleInTeam` / `whereHasRole` |
| `withTeam()` (facade) | facade | run a callback under a temporary permissions team, restoring the previous one |
| `Database\PermissionSync` | service | `plan()` (diff) / `apply(bool $prune)`; backs `AuthorizationSeeder` |
| `authorization:sync` | command | sync permissions/role grants; `--dry-run` preview, `--prune` deletes undeclared permissions |
| `Testing\InteractsWithAuthorization` | trait | test primitives for team-aware role state |
| `make:authorization-policy {Model}` | command | scaffolds a policy |

The core `AuthorizationServiceProvider` is **auto-discovered**. It binds the
manager/resolver/strategy, registers the single `Gate::before`, registers the
teams middleware (only when `config('permission.teams') === true`), registers the
generator command, and publishes the provider stub.

---

## 3. Design decisions & invariants — refactor TOWARD these

These are the conventions the app must converge on. Treat divergences in the app's
current code as **things to fix**, not preserve.

- **Bypass is god-mode only, and never denies.** `Gate::before` returns `true`
  (grant) or `null` (fall through) — **never `false`** (that would veto a
  legitimately-granted permission). Only a true super-admin bypasses.
- **Tenancy lives in policies (`ownsModel()`) and/or Spatie team-scoped
  permissions — never in a scoped `Gate::before`.** A "tenant admin who bypasses
  within their tenant" is an anti-pattern: give them team-scoped permissions
  instead, and let `ownsModel()` fence ownership. Keep `Gate::before` for the
  global super-admin only.
- **System abilities are app-defined.** The package ships no `SystemAbility` enum.
  The app declares its own enum and `Gate::define(...)` calls in the published
  provider. They are never seeded as permissions.
- **A role owns its permissions** via `AuthorizationRole::permissions()` — not a
  `forRole()` map in a registry. Move any such map onto the enum.
- **Always authorize through the Gate** (`$user->can(...)`, `authorize()`, `@can`).
  The only sanctioned direct role read is the `isSuperAdmin()` that feeds the
  bypass. Replace scattered `hasRole()` authorization checks with Gate checks.
- **Permission names have one source** (`PermissionRegistry::nameFromAbility()`),
  so seeded names always equal checked names. Don't hand-build permission strings.
- **The guard is derived** from `config('auth.defaults.guard')` — don't hardcode
  `'web'`.
- **Package code has zero `App\` references.** Anything app-specific (role enum,
  models, concrete policies, system abilities, tenancy keys) stays in the app.

---

## 4. Migration steps (existing app)

Adapt to the project; the order is a guide, not a script.

1. **Require the package.** `composer require apavliukov/laravel-authorization`.
   Ensure `spatie/laravel-permission` is installed and its migrations are run.
   Decide teams on/off (§0.2) — set `config('permission.teams')` accordingly.
2. **Delete the app's local generic auth core** — the equivalents of
   `AbstractPolicy`, `PermissionRegistry`, the bypass registrar, the manager/
   contracts, `HasPolicy`, the CRUD `Ability` enum. Keep the app-specific pieces
   (role enum, concrete policies, `User`, custom abilities, system abilities).
3. **Rewrite namespaces** of the kept code that referenced the old core →
   `AlexPavliukov\Authorization\...` (policies' `extends`, `Ability` imports,
   `HasPolicy` import, blade `@can` unaffected).
4. **Role enum** `implements AuthorizationRole`: add `isSuperAdmin()` and
   `permissions(): string[]`. Move presentation (`label()`, `color()`, …) to an
   app-only trait/concern — it must not be in the auth contract.
5. **User model**: `use Spatie\Permission\Traits\HasRoles;` and
   `use AlexPavliukov\Authorization\Concerns\HasPolicy;`.
6. **Publish + wire the provider**: `php artisan vendor:publish --tag=authorization-provider`,
   register it in `bootstrap/providers.php`, fill in `useRoleEnum(Role::class)` and
   `authorizableModels([...])`. Add the app's system abilities there
   (`Gate::define(SystemAbility::X, fn () => false)`).
7. **Policies**: each `extends AbstractPolicy`, implements `getModelClass()`.
   Apply tenancy and custom abilities via the recipes in §5.
8. **Seeding**: replace the app's permission/role seeders with a call to
   `AuthorizationSeeder` from `DatabaseSeeder`/consistency seeder
   (`$this->call([AuthorizationSeeder::class])`). Delete the old ones. For
   on-demand syncing replace any bespoke sync command with `authorization:sync`
   (`--dry-run` / `--prune`).
9. **Bypass**: default is `RoleBypass`. Switch with `Authorization::bypassUsing(...)`
   in the provider if the project needs `NoBypass` or `protected` abilities.
10. **Run the app's test suite** and fix fallout. Re-seed.

---

## 5. Recipes

**Tenancy by attribute (no Spatie teams)** — e.g. `company_id`. Make an app base:

```php
abstract readonly class CompanyScopedPolicy extends AbstractPolicy
{
    protected function ownsModel(Authenticatable $user, Model $model): bool
    {
        return $user->company?->id === $this->companyId($model);
    }

    protected function companyId(Model $model): ?int
    {
        return $model->company_id; // override per policy when reached via a relation
    }
}
```

**Tenancy with Spatie teams** — turn teams on; role/permission assignments become
team-scoped automatically (set the team id per request via `SetPermissionsTeam`,
which the package registers when teams are on). Use `ownsModel()` only as an extra
ownership fence if needed. No bypass for tenant admins.

**"Manage across the tenant OR own it"** — override the method (CRUD methods are
**not** final); reuse the base for the own-and-can branch:

```php
public function view(Authenticatable $user, Model $model): bool
{
    return $user->can('manage_across_tenant things') || parent::view($user, $model);
}
```

**Custom (non-CRUD) ability** — app enum + expose on the model + a new policy
method (only the 7 base methods exist in the base; adding methods is fine):

```php
public static function getCustomAbilities(): array { return ReviewAbility::cases(); }

public function publish(Authenticatable $user, Model $model): bool
{
    return $this->ownsModel($user, $model) && $this->userCan($user, ReviewAbility::PUBLISH, $model);
}
```

**Restrict which abilities a model exposes** (e.g. no SoftDeletes) — override
`getBasicAbilities()` to drop `RESTORE`/`FORCE_DELETE`.

**Custom bypass** — implement `Contracts\BypassStrategy` and
`Authorization::bypassUsing(YourStrategy::class)`, or rebind the contract. The
strategy receives `(Authenticatable $user, string $ability)` — **no model**, by
design (model-scoped logic belongs in policies, not the bypass).

**Team-aware role reads (teams on)** — ask about role membership in a *specific*
team, *any* team, or globally, **without** switching the active team — replacing
hand-written `model_has_roles` queries. Add `Concerns\HasTeamAwareRoles` to the
model for the query scopes:

```php
Authorization::userHasGlobalRole($user, Role::PLATFORM_ADMIN);   // team_id IS NULL
Authorization::userHasRoleInTeam($user, Role::ORG_ADMIN, $teamId);
Authorization::userHasRole($user, Role::ORG_ADMIN);              // in any team
Authorization::userRolesInTeam($user, $teamId);                 // list<string> of role names (null = global)

User::query()->whereHasGlobalRole(Role::PLATFORM_ADMIN)->get();
User::query()->whereHasRoleInTeam(Role::ORG_ADMIN, $teamId)->get();
User::query()->whereHasRole(Role::ORG_ADMIN)->get();
```

These reads are **memoized per request** (the service is bound `scoped`, so the
memo flushes on each Octane request / queue job; keyed by model identity, so it
never leaks across users). After mutating a user's roles and reading them again in
the same request, call `Authorization::forgetUserRoles($user)`. For a UI that
works in terms of a Spatie `role_id`, bridge it to a name at the boundary
(`Role::findById($id)->name`) and use the name-based reads — the package keeps a
single name/enum identity axis.

**Temporary team context** — run a callback under a given team, restoring the
previous one (even on throw):

```php
Authorization::withTeam($teamId, fn () => $user->assignRole($role));
```

**Session / closure team resolution** — when the tenant comes from session or
request context rather than a column on the user, pass a closure (wrapped in
`CallbackTeamResolver`); return `null` for "no team" (e.g. a platform admin):

```php
Authorization::resolveTeamsUsing(
    fn (Request $request): int|string|null => $request->session()->get('current_team_id'),
);
```

**Permission sync / prune** — seed idempotently via `AuthorizationSeeder`, or run
the `authorization:sync` command: `--dry-run` previews the create/remove +
grant/revoke diff, `--prune` deletes permissions the registry no longer declares
(opt-in; only when permissions are managed solely through the package).
`PermissionSync::plan()` returns the same diff programmatically.

**Testing** — `use Testing\InteractsWithAuthorization` for team-aware test
primitives (`assignRoleInTeam()`, `withPermissionsTeam()`, `roleModelId()`,
`resetPermissionsTeam()`) instead of re-implementing the Spatie plumbing.

---

## 6. Anti-patterns to remove during the refactor

- A second/scoped `Gate::before` for tenant admins → replace with team-scoped
  permissions + `ownsModel()`.
- A `Gate::before` that returns `false` → it must return `null` to fall through.
- System abilities living in the package, or seeded as DB permissions.
- A `forRole()`/permission map in a registry → move onto `Role::permissions()`.
- Direct `hasRole()` checks used for authorization → use the Gate.
- Hand-built permission strings → always `PermissionRegistry::nameFromAbility()`.
- Re-implementing CRUD policy methods just to add an ownership check → use
  `ownsModel()`.
- Hand-written `model_has_roles` queries / `setPermissionsTeamId()` juggling to read
  a role in another team → use the team-aware reads (`userHasRoleInTeam` /
  `userRolesInTeam` / `whereHasRole*`) and `withTeam()`.
- A bespoke per-request memo on the user model for a role check → the team-aware
  reads are already memoized; use `forgetUserRoles()` to invalidate.

---

## 7. Acceptance criteria for a finished adoption

- The app's test suite is green; permissions seed via `AuthorizationSeeder` and
  seeded names match checked names.
- No local generic auth core remains — only app-specific code (role enum, policies,
  `User`, custom/system abilities, the published provider).
- All authorization goes through the Gate; the only direct role read is the
  super-admin check feeding the bypass; `Gate::before` grants god-mode only and
  never returns `false`.
- Tenancy is enforced via `ownsModel()` and/or Spatie team-scoped permissions, not
  via a scoped bypass.
- If teams are off, no team middleware runs; if on, the team id is set per request.

---

## 8. Reference

The package README documents installation, setup, abilities, the bypass, seeding,
and teams from the consumer's point of view. This guide complements it with the
*why* and the *migration*. When in doubt about behavior, read the package source —
it is small and the contracts (`AuthorizationRole`, `BypassStrategy`,
`TeamResolver`) are the only seams between the core and the app.
