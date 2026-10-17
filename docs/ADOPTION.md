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

- **Composer:** `apavliukov/laravel-authorization` (`^1.2`)
- **Namespace:** `AlexPavliukov\Authorization\`
- **Requires:** PHP `^8.4`, Laravel `^13`, `spatie/laravel-permission ^8.0`

---

## 0. Before you start — resolve these with the project/human

These are **not inferable from the package**. Read the app's current auth code and
decide each one explicitly before refactoring:

1. **Is there multi-tenancy?** If yes, the package's tenancy layer is the answer —
   not per-app scopes. Set `authorization.tenancy.model`. **Membership = a role in
   the tenant's team (`model_has_roles.team_id`), nothing else** — delete any
   `users.<tenant>_id` column or membership pivot; `HasMembers` / `HasTenantMemberships`
   give the relations, `TenantResolver` picks session choice → sole tenant → none.
   Tenant-owned models take `BelongsToTenant`; models that reach the tenant through
   another model override `tenantRelationPath()`. Wire `SetCurrentTenant` explicitly
   in `bootstrap/app.php` (`->web(append:)`), and assign every tenant-level role
   *inside* the tenant (`TenantMembership`) — a global role is invisible while a
   tenant is active. Decide `tenancy.owner_role` (who owns a tenant, see §3
   "Ownership") and `tenancy.memberships` (`multiple` / `single`). See README "Tenancy".
2. **Spatie teams: on or off?** With tenancy on, teams are **on** and the tenant is
   the team — no choice. Without tenancy, turn teams on only if you need
   team-scoped role assignments. **Platform-level (global) roles under teams** are
   assigned with a `NULL` pivot `team_id` — but Spatie's stock teams migration
   makes `model_has_roles.team_id` `NOT NULL` and part of the primary key. Make
   that column **nullable** and replace the primary key with a unique index that
   includes `team_id`.
3. **Are roles stored anywhere other than Spatie?** (e.g. a `*_user.role_id`
   pivot.) If yes, decide: **migrate them into Spatie roles/teams** so they fit the
   package, or keep a custom resolver *outside* the package (the package then only
   provides `AbstractPolicy` + `ownsModel()` and you keep your own `Gate::before`).
   Prefer migrating — it's the whole point of unifying.
4. **Which role(s) are super-admin?** (`AuthorizationRole::isSuperAdmin()` → true.)
   **Under teams**, the bypass reads the **global** assignment
   (`team_id IS NULL`) regardless of the active team: a platform admin bypasses
   inside any tenant; a tenant-scoped copy of the role never bypasses. Identity and
   bypass are both team-agnostic — delete any local `holdsPlatformAdminRole()`-style
   workaround.
5. **System abilities** the app needs (model-less gates, e.g. "access admin area").
6. **Custom (non-CRUD) abilities** per model (e.g. `impersonate`, `publish`).

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
Authorization (facade)            useRoleEnum / authorizableModels
                                  withTeam / userHasGlobalRole / userHasRoleInTeam / userHasRole / userRolesInTeam / primaryRole / forgetUserRoles

PermissionRegistry   ability + model → "{ability words} {table}"  (e.g. "view any users")
Support\Permissions  fluent builder for AuthorizationRole::permissions() (for/only/forAll/all)
AbstractPolicy       view/update/delete/restore/forceDelete = ownsModel() && userCan()
                     viewAny/create = userCan()    ownsModel() default true (override to scope)
TenantScopedPolicy   ownsModel() = current tenant === model's owning key (attribute tenancy)
Gate::before (provider)   super-admin bypass — true for a super-admin, else null; never false
PermissionSync + AuthorizationSeeder   apply(): idempotent permission + role-grant seeding
Team-aware reads     userHas{Global,InTeam,}Role / userRolesInTeam / primaryRole / userCan + HasTeamAwareRoles query scopes (role reads memoized per request)
Testing\InteractsWithAuthorization   test primitives (assignRoleInTeam / withPermissionsTeam / roleModelId / resetPermissionsTeam)
Tenancy (config-driven)   CurrentTenant (+ Tenancy facade) | BelongsToTenant + TenantScope (fail-closed) | TenantResolver (session → sole tenant → none)
                          SetCurrentTenant / EnsureTenantSelected / EnsurePlatformArea | TenantMembership (add/changeRole/remove/assignGlobal/removeGlobal/transferOwnership/tenantIdsOf)
                          Concerns\HasMembers (tenant) / Concerns\HasTenantMemberships (user) — relations through model_has_roles
Concerns\HasAppRole      $user->app_role / $user->is_platform_admin (team-agnostic identity; also without tenancy)
Testing\InteractsWithTenancy   actingAsInTenant / withoutTenant / forTenant / assertFailsClosed
```

Resolution order for any `can()` / `authorize()` / `@can`:

1. `Gate::before` → `true` for a bypassing user (super-admin), else `null` (fall through). **Never `false`.**
2. Spatie permission check (the policy's `userCan()` builds the permission string).
3. Policy method (for model-bound abilities) / `Gate::define()` (for system abilities).

---

## 2. Public API surface (what the app touches)

| Symbol | Kind | Use |
|---|---|---|
| `Authorization` (facade) | facade | `useRoleEnum()`, `authorizableModels()` |
| `Contracts\AuthorizationRole` | interface | implemented by the app's role enum: `isSuperAdmin(): bool`, `permissions(): string[]`, static `byPriority(): list<self>` |
| `AbstractPolicy` | abstract | extend per model; implement `getModelClass()`; override `ownsModel()` for tenancy |
| `Concerns\HasPolicy` | trait | on each policy-protected model; `getBasicAbilities()` / `getCustomAbilities()` |
| `Enums\Ability` | enum | the 7 CRUD abilities |
| `PermissionRegistry` | service | `nameFromAbility()`, `allPermissions()` (rarely called directly) |
| `Database\AuthorizationSeeder` | seeder | call from the app's seeder |
| team-aware reads (facade) | facade | `userHasGlobalRole()`, `userHasRoleInTeam()`, `userHasRole()` (any team), `userRolesInTeam()`, `forgetUserRoles()` — memoized per request |
| `userCan()` (facade) | facade | `$user->can()` for a permission name or an (ability, model) pair; policies route through it |
| `Concerns\HasTeamAwareRoles` | trait | query scopes `whereHasGlobalRole` / `whereHasRoleInTeam` / `whereHasRole` |
| `withTeam()` (facade) | facade | run a callback under a temporary permissions team, restoring the previous one |
| `Database\PermissionSync` | service | `apply()`; backs `AuthorizationSeeder` |
| `Testing\InteractsWithAuthorization` | trait | test primitives for team-aware role state |
| `TenantScopedPolicy` | abstract | attribute-tenancy base on `Tenancy::id()` + `tenancy.foreign_key`; override `tenantKey()` for relations |
| `Support\Permissions` | builder | fluent `make()->for()->only()->forAll()->all()` for `AuthorizationRole::permissions()` |
| `primaryRole()` (facade) | facade | highest-priority role the user holds (first held case in `byPriority()`), for routing |

The core `AuthorizationServiceProvider` is **auto-discovered**. It binds the
manager, registers the single `Gate::before`, wires the tenancy layer when
`tenancy.model` is set, and publishes the provider stub and the config.

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

### Tenancy conventions — every app converges here

Decided 2026-10-10 across the four apps that share this package (starter, opiny,
oohire, lead-crm); treat divergences as things to fix.

- **Membership = a role row in `model_has_roles` with `team_id`. Nothing else.** No
  `users.<tenant>_id`, no membership pivot, no JSON. A user may hold roles in any
  number of tenants plus global roles (`team_id NULL`); "one role per user" is not a
  rule. Reads: `tenants()` / `members()` / `TenantMembership::tenantIdsOf()`. Writes:
  `TenantMembership` only (`add / changeRole / remove / assignGlobal / removeGlobal /
  transferOwnership`). Memberships are removed one tenant at a time — there is no
  "detach everywhere".
- **One resolver**: session choice (validated against membership; a global super-admin
  may enter any existing tenant) → the user's sole tenant → none. Several tenants and
  no choice ⇒ no tenant, never an arbitrary row. An app with multi-membership therefore
  ships an organization picker (writes `tenancy.session_key`) and points
  `missing_tenant_redirect` at it — the picker does no authorization of its own.
- **Platform admin is a global role**; identity (`is_platform_admin`) and bypass are
  team-agnostic. The platform area is `EnsurePlatformArea` (deny-all
  `accessPlatformAdmin` gate + request-wide bypass), not an identity check in
  middleware. The gate is always registered — never `Gate::define` it by hand.
- **Isolation = `BelongsToTenant`, fail-closed.** `Tenancy::withoutTenant()` /
  `forTenant()` are the only bypass; never `withoutGlobalScope()` at a call site. Every
  sessionless entry point (job, command, seeder, webhook, public page) states its tenant
  context explicitly. `TenantScopedPolicy` stays as defence in depth. Cross-tenant route
  bindings 404 from the scope; 403 only where the model is reachable (platform area).
- **A nullable tenant key is a shared pool, not a relaxed scope**: read it through one
  explicit query under `withoutTenant()` + `whereNull`, "claim" is an Action that sets
  the key. The scope is never relaxed for `NULL` rows.
- **Naming**: `Organization` / `organization_id` are the config defaults and the name
  every app converges on (rename `Company`, `Team`, …). URLs: `/platform/*` platform
  area, `/admin/*` tenant area, `/candidate/*` where that audience exists, `/settings/*`
  area-less; route names keep the `admin.{area}.` prefix. Public slug routes reserve every first segment the router already owns —
  derive the list from the router, do not hand-maintain it.
- **Area ≠ tenant.** The UI area (platform / organization / …) is the app's routing
  concern; `CurrentTenant` answers only "which tenant". A view-mode switcher sets
  the area, an organization picker sets the tenant.
- **The app owns**: the tenant model and its CRUD, the role enum, policies, business
  invariants, and what happens to a deleted tenant's child records. **Tenants are
  archived (soft delete)**, not hard-deleted: members lose access through the resolver,
  which treats a soft-deleted tenant as gone. This supersedes the earlier
  `throw_if($organization->members()->exists(), …)` delete guard. **The package owns the plumbing** — any Spatie/tenancy plumbing you write
  twice belongs in the package, not in the app.
- **UI split** (starter reference): the user form edits identity plus the global
  platform role; memberships and their roles are managed on the tenant
  (`OrganizationMemberList`; `AddOrganizationMember` = `TenantMembership::changeRole`, so
  re-adding changes the role; `RemoveOrganizationMember`). No membership repeater on
  the user form — saving a user must never touch memberships.

### Ownership (1.3)

- **`<tenant>.owner_id` is the only record of ownership** — `NOT NULL`, FK → users,
  `restrictOnDelete`, in the app's migration. Turn the feature on with
  `tenancy.owner_role => Role::OWNER` (`owner_key` defaults to `owner_id`). While
  `owner_role` is null, ownership is inert — a 1.2 app keeps working unchanged.
- **The owner's role row is derived**: the package writes it together with the column
  (`TenantMembership::transferOwnership($tenant, $newOwner, $demoteTo = null)`, also
  right after creating a tenant with `owner_id` set). `add / changeRole / remove` on the
  owner, and handing the owner role to anyone else, throw `Tenancy\OwnershipException`.
- **The column never grants access.** Policies, the resolver and `members()` read roles
  only; a missing owner row means no access until the repair runs.
- **An owner cannot be deleted**: the FK stops a hard delete, a package `deleting`
  listener on the user model stops soft and hard Eloquent deletes (archived tenants
  count). A soft delete through the query builder (`User::query()->delete()`) bypasses
  it — don't.
- **Self-healing**: `AuthorizationSeeder` re-derives
  every tenant's owner row from `owner_id` and drop any other owner-role row in the team.
- **`tenancy.memberships => 'single'`** makes `add / changeRole / transferOwnership`
  throw `Tenancy\MembershipPolicyException` for a user who already holds a role in
  another tenant (global roles don't count). Default `multiple`.
- Apps surface both exceptions (they extend `DomainException`) as a toast / form error
  and disable the owner's "remove" / "delete" buttons.
- **Who may transfer ownership is an ordinary permission.** The owner role is a regular
  role whose `permissions()` the app enumerates (typically the admin set plus a custom
  ability on the tenant model, e.g. `transferOwnership`); only the owner role gets that
  ability, so an admin is denied like any user without a permission. The package ships
  no owner gate and no owner bypass. `TenantMembership::transferOwnership()` is plumbing
  and does not authorize — the app's action calls `authorize()` first; the method itself
  still refuses a deleted new owner, a second tenant under `single`, and races.

Migration recipe for an app:

```php
Schema::table('organizations', function (Blueprint $table) {
    $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
});

// Coming from 1.2 (owner = the OWNER role row): verify, backfill, then tighten.
$owners = DB::table('model_has_roles')
    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
    ->where('roles.name', Role::OWNER->value)
    ->where('roles.guard_name', 'web')
    ->where('model_has_roles.model_type', (new User)->getMorphClass())
    ->whereNotNull('model_has_roles.team_id')
    ->select('model_has_roles.team_id', 'model_has_roles.model_id');

// Every organization (archived ones too) must have exactly one owner row — fix the
// data by hand before migrating; never let the migration pick one for you.
$broken = DB::table('organizations')
    ->leftJoinSub($owners, 'owners', 'owners.team_id', '=', 'organizations.id')
    ->groupBy('organizations.id')
    ->havingRaw('count(owners.model_id) <> 1')
    ->pluck('organizations.id');

throw_if($broken->isNotEmpty(), RuntimeException::class, 'Organizations without exactly one owner: '.$broken->implode(', '));

$owners->pluck('model_id', 'team_id')->each(
    fn ($userId, $organizationId) => DB::table('organizations')->where('id', $organizationId)->update(['owner_id' => $userId]),
);

Schema::table('organizations', fn (Blueprint $table) => $table->foreignId('owner_id')->nullable(false)->change());
```

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
4. **Role enum** `implements AuthorizationRole`: add `isSuperAdmin()`,
   `permissions(): string[]` and static `byPriority()` — every case exactly once,
   highest priority first (sync throws otherwise). The order is a product decision,
   not a permission count: write down the order the app relies on today (e.g. oohire
   lists `CANDIDATE` before `ORGANIZATION_ADMIN` so an admin who is also a candidate
   lands in the candidate area). Move presentation (`label()`, `color()`, …) to an
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
   (`$this->call([AuthorizationSeeder::class])`). Delete the old ones, including
   any bespoke sync command.
9. **Run the app's test suite** and fix fallout. Re-seed.

---

## 5. Recipes

**Tenant fence in a policy** — extend the package's `TenantScopedPolicy`; it compares
`Tenancy::id()` with the model's `tenancy.foreign_key` column (defence in depth
beside `BelongsToTenant`):

```php
// most policies: just the model
final readonly class LocationPolicy extends TenantScopedPolicy
{
    protected function getModelClass(): string { return Location::class; }
}

// relation-walked model: override tenantKey()
final readonly class ReviewPolicy extends TenantScopedPolicy
{
    protected function getModelClass(): string { return Review::class; }

    protected function tenantKey(Model $model): int|string|null
    {
        return $model->location?->organization_id;
    }
}
```

**Tenancy with Spatie teams** — turn teams on; role/permission assignments become
team-scoped automatically (`SetCurrentTenant` sets the team id per request; teams
without tenancy are the app's own middleware). Use `ownsModel()` only as an extra
ownership fence if needed. No bypass for tenant admins.

**"Manage across the tenant OR own it"** — override the method (CRUD methods are
**not** final); reuse the base for the own-and-can branch:

```php
public function view(Authenticatable $user, Model $model): bool
{
    return $user->can('manage_across_tenant things') || parent::view($user, $model);
}
```

**Role permission set** — build `AuthorizationRole::permissions()` with the
`Permissions` fluent builder instead of hand-assembling through the registry:

```php
self::OWNER => Permissions::make()
    ->for(Company::class)->only(Ability::VIEW, Ability::UPDATE)
    ->forAll(Location::class, Form::class, Review::class)
    ->all(),
```

**System (model-less) abilities** — keep the app's enum; register a deny-by-default
gate per case in the provider (only the super-admin bypass grants it), check with `@can`:

```php
Gate::define(SystemAbility::ACCESS_PLATFORM_ADMIN->value, static fn (): bool => false);
```

**Primary role for routing** — replace a hand-rolled "highest-priority role" loop
with `Authorization::primaryRole($user)` (first held case in `byPriority()`,
team-agnostic); the landing/priority stay on the enum:

```php
$role = Authorization::primaryRole($user) ?? Role::default();
return $role->landingUrl();
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

**Permission sync** — seed idempotently via `AuthorizationSeeder` on every deploy.
Permissions the registry no longer declares are left in place.

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
- A `users.<tenant>_id` column or a `<tenant>_user` pivot kept in sync with
  `model_has_roles` by hand → delete it; membership is the role row.
- Copy-pasted `->when($isPlatformAdmin, fn ($q) => $q->withoutGlobalScope(...))`
  branches → `EnsurePlatformArea` enters the bypass once per request.
- The `registrar->teams = false` detach trick → `TenantMembership::remove()` per tenant,
  `removeGlobal()` for platform roles.
- Hand-written "the owner cannot be removed / deleted" checks → `OwnershipException`.
- Local role-identity accessors (`isPlatformAdmin`, `holdsPlatformAdminRole()`,
  `appRoleOrNull()`, `isAdmin` / `isMember`) → `HasAppRole` (`app_role`,
  `is_platform_admin`).
- A tenant resolved from "the first `team_id` row" → the package resolver; several
  memberships without a session choice land on the picker.

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
- With tenancy on: no second membership record exists; every `BelongsToTenant` model
  passes `assertFailsClosed()`; a user with several memberships and no session choice
  is redirected to the picker, never 403 and never an arbitrary tenant.
- The role enum's `byPriority()` lists every case exactly once, in the order the
  product wants (the seeder passes).
- With ownership on: every tenant has an `owner_id` and its owner role row, and
  a re-run of the seeder reports `0 created, 0 removed`.

---

## 8. Reference

The package README documents installation, setup, abilities, the bypass, seeding,
and teams from the consumer's point of view. This guide complements it with the
*why* and the *migration*. When in doubt about behavior, read the package source —
it is small and `Contracts\AuthorizationRole` is the only seam between the core
and the app.
