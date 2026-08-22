# Tenancy layer — design brief

Status: **implemented in the package** (2026-08-22, ships as `1.1.0`); rollout (§5) pending.
§4 was resolved as: 1 — model + key via `config/authorization.php` (not hard-coded);
2 — throw (`tenancy.strict`); 3 — 404 from the scope; 4 — no base model, the app owns it
(no `Tenant` contract either: membership = Spatie team role); 5 — lifted as
`TenantMembership`; 6 — minor version. Kept below as the original hand-off: it records what was decided, the evidence the
decisions rest on, and the questions that session must still resolve. Treat every
"decided" item as settled unless the human reopens it; treat every "open" item as a
question to ask before coding.

Context that produced it: an audit of the four apps that share this package's shape —
`laravel-starter` (template), `oohire`, `opiny`, `lead-crm` (all under `/home/sites`).
The audit findings are summarised in §2; the per-file references there are accurate as
of 2026-08-22.

---

## 1. Decisions (settled)

1. **One tenancy layer lives in this package**, not per app and not in a second package.
   Tenancy and authorization are coupled through the super-admin bypass and Spatie teams;
   splitting is premature.
2. **Spatie teams is the role-within-tenant mechanism.** Teams stay on whenever the app
   has tenants; the Spatie team *is* the tenant.
3. **Two tenant-resolution modes, one code path.** The modes differ *only* in the
   resolver strategy:
   - `FromUser` — one tenant per user, read from a user column (1:1 apps, the starter).
   - `FromSession` — user belongs to many tenants, the active one is switchable and held
     in session (oohire).
   Everything downstream (scope, route-binding fence, policy fence, Spatie team, queue
   propagation, area dispatch) consumes the same `CurrentTenant` service and does not
   know which mode is active. This is the whole extensibility axis; nothing else is
   parameterised.
4. **Data isolation = global scope, fail-closed.** A model carrying the tenant trait is
   filtered by the current tenant. **No current tenant and no explicit bypass ⇒ empty
   result set** (and an exception in non-production). Never "no tenant ⇒ no filter".
5. **One named bypass API, no ad-hoc `withoutGlobalScope` at call sites.**
   `Tenancy::withoutTenant(fn)` / `Tenancy::forTenant($tenant, fn)` (names provisional).
   Platform-area requests run inside the bypass explicitly (middleware), not by virtue
   of a null tenant.
6. **Platform admin is a global role** (`model_has_roles.team_id IS NULL`) and both its
   *identity* and its *bypass* are team-agnostic. Platform-area access is the deny-all
   `ACCESS_PLATFORM_ADMIN` gate + bypass (the starter/opiny idiom), not an identity check
   in middleware (the oohire idiom).
7. **Canonical tenant model name: `Organization`, column `organization_id`**
   (proposed — see open question 1). Apps may alias in UI copy, not in code.
8. **lead-crm is out of tenancy scope.** Its `Team` is a visibility group inside one
   tenant, not a tenant: no `team_id` on any domain table. It adopts the package's
   authorization core only. If it ever becomes multi-tenant, `Organization` sits *above*
   `Team`.
9. **Area dispatch is a separate concern from the tenant.** oohire's
   `CurrentOrganization` mixes "which tenant" with "which UI area/view mode"; the package
   splits them (`CurrentTenant` vs `CurrentArea`). The auto-routing `x-layouts.admin`
   keys off the area.
10. **The starter carries the reference implementation** (FromUser mode, one
    `Organization`, platform + member areas). The global `laravel-app-patterns` skill's
    `topologies.md` "Tenancy models" section collapses from four options to this one (+
    "none").

---

## 2. Evidence — what the four apps do today and why

| | starter | opiny | oohire | lead-crm |
|---|---|---|---|---|
| Tenant | none | `Company`, `User hasOne Company` (1:1) | `Organization`, `User belongsToMany`, per-org role | none (`Team` = flat lookup, no `team_id` on domain tables) |
| Current tenant | — | derived from user (`$user->company`) | session `current_organization_id` via `App\Support\CurrentOrganization` | first `model_has_roles.team_id` row, no `orderBy` |
| Isolation | — | relationship-walking, 0 global scopes, bindings unscoped | global scope (4 of ~10 models) + `resolveRouteBinding` + auth-aware scopes + policy fence | manual `LeadVisibilityScope` at 5 sites |
| Policy fence | — | `TenantScopedPolicy`, `authorize()` by hand (~11 sites) | `FencesToCurrentOrganization` | ownership in 2 policies only |
| Platform admin | `ACCESS_PLATFORM_ADMIN` deny-all gate | same via `systemAbilities()` | `is_platform_admin` identity check; bypass copy-pasted `->when($isPlatformAdmin, withoutGlobalScope)` in ~10 components | deny-all gate; `routes/platform.php` empty |
| Spatie teams | off | off | **on** | **on** (nullable `team_id`) |
| Layout dispatch | `Role::layout()` in sidebar/dashboard | `@can(ACCESS_PLATFORM_ADMIN)` | `CurrentOrganization::currentArea()` | single layout |

Why the current choices were made (documented in each repo):

- opiny (`docs/superpowers/specs/2026-06-20-authorization-roles-permissions-design.md`)
  rejected global scopes because with 1:1 they "complicate the super-admin bypass and add
  boot-time queries for marginal gain", and dropped teams because there is one member.
  Both arguments disappear once the layer is shared.
- oohire needs session tenancy because of multi-membership + switcher. Its scope is
  fail-open *deliberately* (platform admins and queue workers read without an org).
- lead-crm deferred `current_team_id` to a future impersonation/team-switching phase.

Where it actually broke (git history, not theory) — **every incident is "missing filter
or null context ⇒ leak"**, which is what decision 4 closes:

- oohire `47d9d74`, `2307ab9`, `c368139`: all variants of the fail-open scope (null org in
  session → cross-tenant reads; queue worker has no session → `SendTemplateEmailJob`
  unscoped). `d6d6bd2`: Spatie `whereDoesntHave('roles')` misses the `team_id = NULL`
  platform admin when a team is active. `app/Models/User.php:241` admits "three role
  storage mechanisms".
- opiny: no backstop — a forgotten `authorize()` is a leak. Child models without a policy
  (`FormElement`, `FormChoice`) are guarded by hand-written `whereHas` only
  (`FormBuilderCrossTenantTest` documents the attack).
- lead-crm: three live IDORs — `LeadDetailModal::openLead()` fetches by id without the
  visibility scope; `TEAM_LEAD` can edit any user by URL (`UserPolicy` has no fence);
  `ListCallsQuery` takes no viewer.

### 2.1 Package defect found during the audit

`Support/RoleBypass::shouldBypass()` calls `hasAnyRole()`, which Spatie filters by the
active `team_id`. A global super-admin (`team_id NULL`) therefore **does not bypass while
a tenant is active**. oohire works around it with `User::holdsPlatformAdminRole()`
("identity and bypass are separate concerns"); lead-crm swaps the registrar team id
inside `User::appRole()`. Fix once, in the package: super-admin identity and bypass read
the global assignment regardless of the active team (`whereHasGlobalRole` already
exists for queries; the bypass needs the equivalent on the user).

### 2.2 What the package already has (reuse, don't rebuild)

- `Contracts/TeamResolver`, `Teams/DefaultTeamResolver` (reads a user column — this *is*
  FromUser mode), `Teams/CallbackTeamResolver` (FromSession mode in disguise),
  `Teams/SetPermissionsTeam` middleware, `AuthorizationManager::withTeam()`.
- `TenantScopedPolicy` + `resolveTenantUsing()` + `tenantColumn()` — the policy-level
  fence. Keep it; it becomes defence-in-depth under the global scope.
- `Concerns/HasTeamAwareRoles` — `whereHasGlobalRole / InTeam / Role`.
- `Testing/InteractsWithAuthorization::assignRoleInTeam()`.

Note `resolveTenantUsing` and `resolveTeamsUsing` are today two independent closures
that every app wires to the same value. The layer unifies them: `CurrentTenant` is the
single source, and the team resolver reads from it.

---

## 3. Components to build

Names are provisional; keep the shape.

1. **`CurrentTenant`** (singleton) — `id()`, `get()`, `set()`, `forget()`,
   `withoutTenant(callable)`, `forTenant(Model|int, callable)`, `isBypassed()`. `set()`
   also calls `setPermissionsTeamId()`; `forget()` resets it. Resolution strategy
   injected: `FromUserResolver` (column name configurable) | `FromSessionResolver`
   (session key configurable, validates membership). Declared in the app provider:
   `Authorization::resolveTenantFromUser('organization_id')` or
   `Authorization::resolveTenantFromSession()`.
2. **`BelongsToTenant` trait** — `addGlobalScope(TenantScope)`, `creating` autofill,
   `organization()` relation, `resolveRouteBinding()` fence. For transitively-owned
   models (oohire's `Application → campaign.position`), a `tenantRelationPath()` hook so
   the fence walks the relation (lift `ScopesRouteBindingByOrganization`).
3. **`TenantScope`** — fail-closed: no tenant and not bypassed ⇒
   `whereRaw('1 = 0')`; in non-production additionally throw
   `MissingTenantContextException` so tests catch the omission. Bypassed ⇒ no constraint.
4. **Middleware**: `SetCurrentTenant` (resolve + set, replaces `SetPermissionsTeam` or
   wraps it), `EnsureTenantSelected`, `EnsurePlatformArea` (runs the request inside
   `withoutTenant`), plus the deny-all `ACCESS_PLATFORM_ADMIN` gate registration the
   starter does by hand today.
5. **Queue propagation** — a job middleware/trait that serialises the tenant id with the
   job and restores it on the worker (compare `spatie/laravel-multitenancy`'s
   tenant-aware jobs for the mechanism; do not add the dependency). Jobs dispatched
   inside `withoutTenant` restore the bypass.
6. **Super-admin fix** (§2.1) — team-agnostic identity + bypass.
7. **`CurrentArea`** — resolves `platform | organization | member | …` from route-name
   prefix, falling back to a session view mode clamped to the areas the user may access
   (oohire's `currentArea()` minus the tenant parts). Ships a stub `x-layouts.admin`
   pattern for the app to copy, not a published view.
8. **Testing** — `actingAsInTenant($user, $tenant)`, `createTenantAdmin()`,
   `withoutTenant()` wrappers, and an assertion helper that a query outside tenant
   context yields nothing (the fail-closed contract, testable per model).
9. **Docs**: README section, `ADOPTION.md` §0 question 1/2 rewritten to point at the two
   modes, and the shipped `laravel-authorization-development` skill updated.

---

## 4. Open questions for the implementing session (ask, don't guess)

1. **Model name** — `Organization` / `organization_id` as canon? (Proposed by the agent,
   not yet confirmed by the human.) Consequence: opiny renames `Company`; Spatie
   `models.team` → the app's `Organization`.
2. **Non-production behaviour on missing tenant** — throw (loud, may break tests that
   legitimately run unscoped) or only empty-set (quiet, may hide omissions)? Proposal:
   throw, with `withoutTenant()` as the explicit opt-out.
3. **Route-binding fence response** — 404 (scope) or 403 (policy) on cross-tenant access?
   oohire 404s; opiny 403s. Proposal: 404 from the scope, 403 only when the model is
   reachable (platform context).
4. **Does the layer own `Organization` itself** (a base model / contract with
   `members()`, `owner`, `is_active`) or only the `BelongsToTenant` side? Proposal: a
   `Tenant` contract + optional `IsTenant` trait; the app owns the model.
5. **Membership writes** — lift oohire's `OrganizationMembershipService` (`add`,
   `changeRole`, `roleFor` inside `withTeam` + `forgetUserRoles`) into the package, or
   leave per app? Proposal: lift; it is pure Spatie plumbing.
6. **Versioning** — this is a breaking change to `resolveTenantUsing` /
   `resolveTeamsUsing` wiring. `2.0.0`.

---

## 5. Rollout order (after the package ships)

1. **starter** — reference implementation in FromUser mode; update manifest and the
   canon's `topologies.md`.
2. **opiny** — FromUser mode; adds `organization_id` to `reviews` (the one tenant-owned
   table lacking it); the ~11 `authorize()` calls stay as defence-in-depth; audit every
   sessionless query path (jobs, `ConsistencySeeder`, `llm:backfill-usage`,
   `ResolvePublicReviewContext`) for explicit `withoutTenant()`.
3. **oohire** — FromSession mode; deletes the ~10 copy-pasted bypass branches and the
   three local role-identity workarounds; `CurrentOrganization` splits into
   `CurrentTenant` + `CurrentArea`; platform middleware moves to the gate. ~130 test
   files use `InteractsWithOrganizations` — map its helpers onto the package ones rather
   than rewriting tests.
4. **lead-crm** — authorization core only (the local `AbstractPolicy`,
   `PermissionRegistry`, `Gate::before` are deleted in favour of the package's); fix the
   three IDORs in §2 regardless of tenancy.

Fix the manifest drift noted in the audit before using any manifest as input: opiny's
CLAUDE.md claims no auto-routing layout (it has one); oohire's CLAUDE.md/docs/global.md
reference a `.claude/rules/authorization.md` that does not exist and list helpers that
moved to `tests/Concerns/InteractsWithOrganizations.php`; lead-crm's `docs/crm-schema.md`
describes an abandoned schema.
