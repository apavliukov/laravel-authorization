# Laravel Authorization

A reusable, Spatie-permission-based authorization layer for Laravel: resource
policies, ability enums, role semantics, a permission registry, idempotent
seeding, and a pluggable admin bypass.

The package owns the generic core. Your application keeps only what is genuinely
app-specific: the role enum, concrete policies, the user model, and the
declarations wiring them together.

## Requirements

- PHP `^8.4`
- Laravel `^13.0`
- [`spatie/laravel-permission`](https://spatie.be/docs/laravel-permission) `^8.0`

## Installation

The package is published on [Packagist](https://packagist.org/packages/apavliukov/laravel-authorization):

```bash
composer require apavliukov/laravel-authorization
```

Make sure Spatie's permission tables are migrated (publish and run its migrations
if you have not already):

```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

The package's core `AuthorizationServiceProvider` is auto-discovered. It registers
the bindings, the `Gate::before` bypass hook, the `make:authorization-policy`
command, and (when Spatie teams are enabled) the team middleware.

## Setup

### 1. Publish and register the app provider

```bash
php artisan vendor:publish --tag=authorization-provider
```

This writes `app/Providers/AuthorizationServiceProvider.php` — the one place where
your application declares its role enum, authorizable models, and system
abilities. Register it in `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthorizationServiceProvider::class,
];
```

The published provider looks like this:

```php
use AlexPavliukov\Authorization\Authorization;
use App\Enums\Policies\Role;
use App\Models\User;
use Illuminate\Support\ServiceProvider;

final class AuthorizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Authorization::useRoleEnum(Role::class);

        Authorization::authorizableModels([
            User::class,
        ]);

        // Define your app's system (model-less) abilities here, e.g.:
        // Gate::define(\App\Enums\SystemAbility::ACCESS_PLATFORM_ADMIN, static fn (): bool => false);
    }
}
```

### 2. Implement the role enum

Your role enum implements `AuthorizationRole`. `isSuperAdmin()` drives the bypass;
`permissions()` is consumed by the seeder to grant per-role permissions;
`byPriority()` ranks the cases for [`primaryRole()`](#primary-role-for-routing) and
must list every case exactly once — `authorization:sync` and the seeder throw
otherwise.

```php
use AlexPavliukov\Authorization\Contracts\AuthorizationRole;

enum Role: string implements AuthorizationRole
{
    case ADMIN = 'admin';
    case MEMBER = 'member';

    /** @return list<self> */
    public static function byPriority(): array
    {
        return [self::ADMIN, self::MEMBER];
    }

    public function isSuperAdmin(): bool
    {
        return $this === self::ADMIN;
    }

    /** @return array<int, string> */
    public function permissions(): array
    {
        return match ($this) {
            self::ADMIN, self::MEMBER => [],
        };
    }
}
```

Role presentation (labels, colors, layouts) is app-specific and stays out of the
package — keep it on the enum or in a dedicated trait of your own.

### 3. Prepare the user model

The package relies on Spatie's `HasRoles`. Add `HasPolicy` so the model declares
which abilities generate permissions for it.

```php
use AlexPavliukov\Authorization\Concerns\HasPolicy;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasPolicy;
    use HasRoles;
}
```

## Policies

Concrete policies extend `AbstractPolicy` and declare their model. The seven CRUD
methods map each ability to a permission string and check it against the user.

```php
use AlexPavliukov\Authorization\AbstractPolicy;

final readonly class PostPolicy extends AbstractPolicy
{
    protected function getModelClass(): string
    {
        return Post::class;
    }
}
```

Scaffold one with the generator:

```bash
php artisan make:authorization-policy Post
```

### Ownership / tenancy scoping (`ownsModel()`)

The model-bound methods (`view`, `update`, `delete`, `restore`, `forceDelete`)
resolve to `ownsModel($user, $model) && userCan(...)`. By default `ownsModel()`
returns `true` (no fencing). Override it to scope a model to the user — a
`company_id` / `team_id` match, a relation walk, etc. Model-less checks
(`viewAny`, `create`) never consult it.

```php
abstract readonly class CompanyScopedPolicy extends AbstractPolicy
{
    protected function ownsModel(Authenticatable $user, Model $model): bool
    {
        return $user->company?->id === $this->companyId($model);
    }

    protected function companyId(Model $model): ?int
    {
        return $model->company_id;
    }
}
```

The CRUD methods are not `final`, so a policy that needs different logic (e.g.
"manage across the tenant OR own it") can override the method directly and call
`parent::view(...)` for the owns-and-can branch.

### Attribute tenancy (`TenantScopedPolicy`)

For the common case — a model fenced to the current user's tenant by an owning
column — extend `TenantScopedPolicy` instead of hand-writing `ownsModel()`. With
the [tenancy layer](#tenancy) configured it reads `CurrentTenant` and
`tenancy.foreign_key` — nothing to declare. Without it, declare once how to read
the tenant from the user and the default owning column:

```php
// in your AuthorizationServiceProvider::boot() — only when tenancy.model is NOT set
Authorization::resolveTenantUsing(static fn (User $user): ?int => $user->company?->id);
Authorization::tenantColumn('company_id'); // default owning column (defaults to tenant_id)
```

```php
use AlexPavliukov\Authorization\TenantScopedPolicy;

// Reached by the default column — only getModelClass() needed:
final readonly class LocationPolicy extends TenantScopedPolicy
{
    protected function getModelClass(): string { return Location::class; }
}

// Reached through a relation — override tenantKey():
final readonly class ReviewPolicy extends TenantScopedPolicy
{
    protected function getModelClass(): string { return Review::class; }

    protected function tenantKey(Model $model): int|string|null
    {
        return $model->location?->company_id;
    }
}
```

The resolver closure may type-hint your concrete user model (`User $user`), so
scoped policies never touch `$user` and need no type narrowing.

## Abilities and permission names

- `Enums\Ability` — the seven standard resource abilities (1:1 with policy
  methods). Values are camelCase so Gate routes them straight to policy methods.
- System abilities (model-less `Gate::define()` checks, e.g. "access platform
  admin") are **app-defined** — declare your own enum; the package ships no
  `SystemAbility` enum. Register deny-by-default gates for it in one call (only the
  super-admin bypass then grants them), and check with `@can(Ability::X->value)`:

  ```php
  Authorization::systemAbilities(\App\Enums\SystemAbility::class);
  ```
- Model-specific abilities are added by overriding `HasPolicy::getCustomAbilities()`:

```php
public static function getCustomAbilities(): array
{
    return PostAbility::cases();
}
```

`PermissionRegistry` converts an ability + model into a permission string, e.g.
`Ability::VIEW_ANY` + `User` → `"view any users"`.

### Building a role's permission set

`AuthorizationRole::permissions()` returns permission-name strings. Build them
fluently with `Permissions` instead of hand-assembling through the registry:

```php
use AlexPavliukov\Authorization\Support\Permissions;

public function permissions(): array
{
    return match ($this) {
        self::SUPER_ADMIN => [],                 // bypass covers it
        self::OWNER => Permissions::make()
            ->for(Company::class)->only(Ability::VIEW, Ability::UPDATE)
            ->forAll(Location::class, Form::class, Review::class)
            ->all(),
    };
}
```

### Primary role for routing

`Authorization::primaryRole($user)` (and `$user->app_role`) returns the
highest-priority role the user holds — the first case in the enum's `byPriority()`
that the user holds globally or in any team — so it suits login redirects and "home"
links regardless of the active team. Declaration order plays no part.

The order is a product decision, not a permission count: it answers "which area does
a user with several roles land in". An app where a candidate who is also an
organization admin must land in the candidate area lists `CANDIDATE` before
`ORGANIZATION_ADMIN`, although the admin holds more permissions.

```php
$role = Authorization::primaryRole($user) ?? Role::default();
return $role->landingUrl(); // landingUrl() is your app's business method
```

## Admin bypass

`Gate::before` is wired through a pluggable `BypassStrategy`, resolved from the
container lazily on each check.

- **`Support\RoleBypass` (default)** — holders of a super-admin role bypass every
  check. It accepts an optional list of *protected* abilities that always fall
  through to policies:

  ```php
  use AlexPavliukov\Authorization\Authorization;
  use AlexPavliukov\Authorization\Enums\Ability;
  use AlexPavliukov\Authorization\Support\RoleBypass;

  Authorization::bypassUsing(new RoleBypass(
      app(\AlexPavliukov\Authorization\AuthorizationManager::class),
      protected: [Ability::FORCE_DELETE],
  ));
  ```

- **`Support\NoBypass`** — no god-mode; every check goes through Spatie/policies:

  ```php
  Authorization::bypassUsing(\AlexPavliukov\Authorization\Support\NoBypass::class);
  ```

With Spatie teams on, `RoleBypass` reads the **global** assignment
(`team_id IS NULL`) regardless of the active team: a platform admin keeps bypassing
inside a tenant, and a tenant-scoped copy of a super-admin role never bypasses.

You can also override the strategy by rebinding the contract in the container:

```php
$this->app->bind(
    \AlexPavliukov\Authorization\Contracts\BypassStrategy::class,
    \App\Authorization\YourStrategy::class,
);
```

**Guiding principle:** a super-admin has the right to do everything. Real "can't"s
are business invariants enforced in the Action/domain layer, not authorization.
`protected` / `NoBypass` exist only for genuine authorization-level carve-outs
(separation of duties, break-glass).

## Seeding

`Database\AuthorizationSeeder` syncs permissions (from your authorizable models)
and roles (from each enum case's `permissions()`). It is idempotent — call it from
your own seeder:

```php
public function run(): void
{
    $this->call([
        \AlexPavliukov\Authorization\Database\AuthorizationSeeder::class,
    ]);
}
```

The same sync is available as a command. Preview the diff with `--dry-run`, and
delete permissions the registry no longer declares with `--prune`:

```bash
php artisan authorization:sync             # create missing permissions + sync role grants
php artisan authorization:sync --dry-run   # show the create/remove/grant/revoke diff, write nothing
php artisan authorization:sync --prune     # also delete permissions no longer declared
```

`--prune` deletes every permission under the guard the registry no longer
declares — enable it only when permissions are managed solely through this
package. `PermissionSync::plan()` returns the same diff programmatically.

## Tenancy

One tenancy layer, switched on by config. Publish it with
`artisan vendor:publish --tag=authorization-config` and set the model:

```php
// config/authorization.php
'tenancy' => [
    'model' => App\Models\Organization::class,   // null = no tenancy
    'foreign_key' => 'organization_id',
    'session_key' => 'current_organization_id',
    'strict' => env('APP_ENV') !== 'production',
    'missing_tenant_redirect' => null,            // route name, e.g. 'organizations.pick'
],
```

The tenant **is** the Spatie team: turn `permission.teams` on and make
`model_has_roles.team_id` nullable (see [Teams](#teams)). The app owns the tenant
model; the package needs nothing from it beyond a primary key.

**Membership = a role row in `model_has_roles` with `team_id`. Nothing else** — no
tenant column on `users`, no pivot table. A user may hold roles in any number of
tenants plus global roles (`team_id NULL`). One resolver (`Tenancy\TenantResolver`):

1. the `session_key` value, honoured only while the user is a member of that tenant
   (a global super-admin may enter any tenant that exists); a stale key is dropped;
2. otherwise the user's **sole** tenant;
3. otherwise none — several tenants and no session choice never pick an arbitrary
   row (`EnsureTenantSelected` then 403s / redirects to `missing_tenant_redirect`).

```php
use AlexPavliukov\Authorization\Tenancy\Concerns\HasMembers;            // tenant model
use AlexPavliukov\Authorization\Tenancy\Concerns\HasTenantMemberships;  // user model
use AlexPavliukov\Authorization\Concerns\HasAppRole;                     // user model

$organization->members();        // MorphToMany users through model_has_roles
$user->tenants();                // MorphToMany tenants through model_has_roles
$user->app_role;                 // highest-priority role held anywhere (enum case) or null
$user->is_platform_admin;        // global super-admin (team-agnostic)
```

Writes go through `TenantMembership` (see [Membership](#membership)).

### Wiring the middleware

The package registers **no** middleware on any group — a push from a provider is
silently dropped once the app configures its own groups. Declare it in
`bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [SetCurrentTenant::class]);
    $middleware->api(append: [SetCurrentTenant::class]);   // if the API is tenant-aware
})
```

The package only fixes its priority (before `SubstituteBindings`, so bindings are
fenced). The middleware sets `CurrentTenant` + `setPermissionsTeamId()`.

**Livewire:** `/livewire/update` replays only *persistent* middleware. When
Livewire is installed the package registers `SetCurrentTenant`,
`EnsureTenantSelected` and `EnsurePlatformArea` as persistent; without that a
platform component's actions would run unbypassed. If you register your own
persistent list, include these three.

**`config:cache`:** `tenancy.strict` reads `env()` — publish the config (or set
`strict` to a literal) in apps that cache configuration.

### Roles live inside the tenant

With tenancy on, Spatie answers `hasRole()` / `can()` against the **active team**.
So every tenant-level role (owner, member, editor …) must be assigned *within* the
tenant — `TenantMembership::add($user, $tenant, Role::ADMIN)` — also when each user
has exactly one tenant. A role assigned globally (`team_id IS NULL`) is invisible
while a tenant is active; only super-admin roles belong there
(`TenantMembership::assignGlobal($user, Role::PLATFORM_ADMIN)`). Seeders and
registration flows that call `$user->assignRole()` directly must run inside
`Tenancy::forTenant()` or use `TenantMembership`.

### Tenant-owned models

```php
use AlexPavliukov\Authorization\Tenancy\BelongsToTenant;

final class Post extends Model
{
    use BelongsToTenant;   // global scope + autofill on create + tenant() relation
}

// Reaches the tenant through another model:
final class Comment extends Model
{
    use BelongsToTenant;

    protected static function tenantRelationPath(): ?string
    {
        return 'post';     // or 'campaign.position'
    }
}
```

On such a transitive model `tenant()` throws a `LogicException` — walk the relation
(`$comment->post->tenant`) instead.

The scope is **fail-closed**: no current tenant and no bypass ⇒ no rows, and a
`MissingTenantContextException` when `strict` is on. Creating with no tenant and no
explicit key always throws, bypass or not — a bypass never produces orphans.
`forTenant()` suspends an outer bypass, so a platform admin reading "as" a tenant
sees only that tenant. Cross-tenant route bindings
404. `TenantScopedPolicy` reads the same `CurrentTenant` (no `resolveTenantUsing()`
needed) and stays as defence-in-depth.

### Bypass and switching

```php
use AlexPavliukov\Authorization\Tenancy\Tenancy;

Tenancy::id();  Tenancy::get();  Tenancy::set($organization);  Tenancy::forget();
Tenancy::withoutTenant(fn () => Post::query()->count());     // all tenants
Tenancy::forTenant($other, fn () => Post::query()->get());   // another tenant
```

Never call `withoutGlobalScope(TenantScope::class)` at call sites — the bypass is
the one named API, and it is what `EnsurePlatformArea` uses.

### Middleware

- `SetCurrentTenant` — resolve + activate (wire it yourself, see above).
- `EnsureTenantSelected` — 403, or redirect to `missing_tenant_redirect`.
- `EnsurePlatformArea` — `Gate::authorize('accessPlatformAdmin')` (a deny-all gate
  the package registers; only the super-admin bypass grants it), then enters the
  tenant bypass for the rest of the request (entered, not wrapped — Livewire's
  replay pipeline returns before the component action runs).

### Queues

The tenant id and bypass flag live in Laravel's hidden `Context`, so they ride
along with every queued job automatically; the worker re-applies the Spatie team
when the context is hydrated. A job dispatched inside `withoutTenant()` runs
bypassed.

### Membership

`Tenancy\TenantMembership` is the Spatie plumbing for "user X has role R in tenant T":

```php
$membership->add($user, $organization, Role::MEMBER);
$membership->changeRole($user, $organization, Role::ADMIN);
$membership->remove($user, $organization);
$membership->roleFor($user, $organization);   // ?string
$membership->isMember($user, $organization);
```

Writes run under the target team, restore the active one, and flush the memoized
role reads.

`assignGlobal / removeGlobal($user, $role)` handle platform roles; `tenantIdsOf($user)`
returns the memoized id list. `tenancy.memberships => 'single'` makes `add` / `changeRole`
/ `transferOwnership` throw `Tenancy\MembershipPolicyException` for a user who already
holds a role in another tenant (global roles don't count; default `multiple`).

**Ownership** (on when `tenancy.owner_role` is set): the tenant's `owner_key` column
(default `owner_id`; the app's migration — `NOT NULL`, FK users, `restrictOnDelete`)
is the only record of who owns it. The owner's role row is derived and written only
together with the column:

```php
'tenancy' => [
    'owner_key' => 'owner_id',
    'owner_role' => Role::OWNER,
    'memberships' => 'multiple',   // or 'single'
],

$organization = Organization::create(['name' => 'Acme', 'owner_id' => $user->id]);
$membership->transferOwnership($organization, $user);          // writes the owner row; idempotent
$membership->transferOwnership($organization, $next, Role::ADMIN); // previous owner demoted (or dropped when null)
$membership->ownerOf($organization);   // via the column
$membership->isOwner($user, $organization);
$organization->owner();                // BelongsTo (HasMembers)
```

- `add / changeRole / remove` on the owner, and granting the owner role to anyone,
  throw `Tenancy\OwnershipException` (a `DomainException`) — transfer ownership instead.
- Deleting a user who owns a tenant (archived ones included) throws
  `OwnershipException` from a `deleting` listener — soft and hard Eloquent deletes
  alike; query-builder deletes bypass it.
- The column never grants access: policies, the resolver and `members()` read roles only.
- `AuthorizationSeeder` re-derives every owner row from the column and removes any other
  owner-role row in the team (`authorization:repair-ownership` runs it ad hoc). Tenants
  with a `NULL` owner are skipped; a missing column is reported and skipped instead of failing.
- The package never deletes tenants or cascades — archive (soft delete) them.

### Areas

`Tenancy\CurrentArea` answers "which UI area" (`platform | organization | member …`)
by matching the route name against the `Str::is` patterns in
`config('authorization.areas')` (default `*platform.*` etc., so `admin.platform.x`
matches), falling back to a session view mode (`set()`) and then the first
configured area. Off-request (console, queue) there is no route, so the fallback
applies. It knows nothing about the tenant — that split is deliberate.

### Testing

```php
use AlexPavliukov\Authorization\Testing\InteractsWithTenancy;

$this->actingAsInTenant($user, $organization, Role::MEMBER);
$this->withoutTenant(fn () => Post::factory()->count(3)->create());
$this->forTenant($other, fn () => /* … */);
$this->assertFailsClosed(Post::class);   // the per-model fail-closed contract
```

## Teams

When Spatie native teams are enabled (`config('permission.teams') === true`)
without the tenancy layer, wire `SetPermissionsTeam` yourself in `bootstrap/app.php`
(`$middleware->web(append: [SetPermissionsTeam::class])`) — the package registers no
group middleware. It resolves the current team id via the bound `TeamResolver`
(default: `DefaultTeamResolver`, which reads the user's `team_foreign_key`
attribute) and calls `setPermissionsTeamId()`. With tenancy on, `SetCurrentTenant`
replaces it.

Provide a custom resolver with `Authorization::resolveTeamsUsing(YourResolver::class)`.

When the team is derived from session or request-scoped context rather than a
column on the user, pass a closure instead — it is wrapped in a
`CallbackTeamResolver` and receives the current `Request`:

```php
Authorization::resolveTeamsUsing(
    fn (Request $request): int|string|null => $request->session()->get('current_team_id'),
);
```

### Temporary team context

`Authorization::withTeam()` runs a callback under a given permissions team and
restores the previous one afterwards — even if the callback throws. Useful for
acting on another tenant's data without leaking team state:

```php
Authorization::withTeam($organizationId, fn () => $user->assignRole('organization_admin'));
```

### Team-aware role reads

Spatie's `hasRole()` and the `role` query scope are bound to the *active* team.
To ask about role membership in a *specific* team — or globally — without
switching the active team, add the `HasTeamAwareRoles` trait to the model:

```php
use AlexPavliukov\Authorization\Concerns\HasTeamAwareRoles;

class User extends Authenticatable
{
    use HasRoles;
    use HasTeamAwareRoles;
}
```

```php
// facade reads
Authorization::userHasRoleInTeam($user, Role::ORGANIZATION_ADMIN, $organizationId);
Authorization::userHasGlobalRole($user, Role::PLATFORM_ADMIN);
Authorization::userHasRole($user, Role::ORGANIZATION_ADMIN);          // in any team (or global)
Authorization::userRolesInTeam($user, $organizationId);              // ['organization_admin', ...] (null = global)

// query scopes
User::query()->whereHasRoleInTeam(Role::ORGANIZATION_ADMIN, $organizationId)->get();
User::query()->whereHasGlobalRole(Role::PLATFORM_ADMIN)->get();
User::query()->whereHasRole(Role::ORGANIZATION_ADMIN)->get();         // holds it in any team
```

A **global role** is one assigned with a `NULL` pivot `team_id` — effective when
no team is active (e.g. a platform-level admin). Storing it requires a **nullable**
`model_has_roles.team_id`: Spatie's stock teams migration makes that column
`NOT NULL` and part of the primary key, so to use global assignments make it
nullable and replace the primary key with a unique index that includes `team_id`.

These reads are **memoized per request** (the underlying service is bound
`scoped`, so the memo is flushed on each Octane request / queue job). The cache is
keyed by model identity, so it never leaks across users. If you mutate a user's
roles and read them again within the same request, drop the memo first:

```php
$user->assignRole($role);
Authorization::forgetUserRoles($user);
```

### Memoized permission checks

`Authorization::userCan()` answers a permission check and memoizes the verdict for
the request, keyed by `(user identity, permission, active permissions team)`. It
sits beside the role reads above and shares their lifecycle (bound `scoped`, so the
memo is flushed on each Octane request / queue job, and never leaks across users or
teams). It wraps `$user->can()`, so the `Gate::before` bypass and any policies still
apply — the memo just avoids re-running the whole Gate pipeline for a check whose
answer is stable within the request. This pays off when an auth-aware query scope
issues the same check on every query:

```php
Authorization::userCan($user, 'view any users');                 // permission name
Authorization::userCan($user, Ability::VIEW, $model);            // (ability, model) pair
```

`AbstractPolicy::userCan()` routes through it, so every policy check is memoized for
free. The memo assumes the verdict is a pure function of `(user, permission, active
team)` for the request; after granting or revoking mid-request, drop it first —
`forgetUserRoles()` also flushes it, since a role change alters effective permissions:

```php
$user->givePermissionTo($permission);
Authorization::forgetUserPermissions($user);   // or forgetUserRoles() after a role change
```

## Testing

`Testing\InteractsWithAuthorization` ships team-aware test primitives so your
suite does not re-implement the Spatie teams plumbing:

```php
use AlexPavliukov\Authorization\Testing\InteractsWithAuthorization;

final class ExampleTest extends TestCase
{
    use InteractsWithAuthorization;

    public function test_example(): void
    {
        $this->assignRoleInTeam($member, Role::ORGANIZATION_ADMIN, $organizationId);
        $this->assignRoleInTeam($admin, Role::PLATFORM_ADMIN, null); // global assignment

        $roleId = $this->roleModelId(Role::ORGANIZATION_ADMIN);
        $this->withPermissionsTeam($organizationId, fn () => /* act within the team */);
        $this->resetPermissionsTeam();
    }
}
```

## Development

```bash
composer install
vendor/bin/phpunit          # test suite (Orchestra Testbench)
vendor/bin/phpstan analyse  # static analysis, level 9
vendor/bin/pint             # code style
```

## License

MIT
