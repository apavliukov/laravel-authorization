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
`permissions()` is consumed by the seeder to grant per-role permissions.

```php
use AlexPavliukov\Authorization\Contracts\AuthorizationRole;

enum Role: string implements AuthorizationRole
{
    case ADMIN = 'admin';
    case MEMBER = 'member';

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
column — extend `TenantScopedPolicy` instead of hand-writing `ownsModel()`. Declare
once how to read the tenant from the user and the default owning column:

```php
// in your AuthorizationServiceProvider::boot()
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

`Authorization::primaryRole($user)` returns the highest-priority role the user
holds — the first matching case in the enum's declaration order — using
team-agnostic identity, so it suits login redirects and "home" links regardless of
the active team:

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

## Teams

When Spatie native teams are enabled (`config('permission.teams') === true`), the
core provider registers `SetPermissionsTeam` on the `web` middleware group. It
resolves the current team id via the bound `TeamResolver` (default:
`DefaultTeamResolver`, which reads the user's `team_foreign_key` attribute) and
calls `setPermissionsTeamId()`. With teams off, none of this is wired.

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
