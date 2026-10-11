# Changelog

All notable changes to this project will be documented in this file. See [commit-and-tag-version](https://github.com/absolute-version/commit-and-tag-version) for commit guidelines.

## [1.3.1](https://github.com/apavliukov/laravel-authorization/compare/v1.3.0...v1.3.1) (2026-10-11)

### Highlights

Security fix for 1.3.0 ownership — upgrade. Guards trusted the `owner_key` attribute of the tenant model passed in, so a tenant loaded without that column (`select(['id', 'name'])`) let the owner be removed or demoted, and a stale model made `transferOwnership()` leave the real previous owner holding the owner role. Guards now read the owner from the database; the transfer reloads the tenant row under `lockForUpdate()` and saves it, so tenant model events still fire. Run `authorization:repair-ownership` once if 1.3.0 was already in production. No API or config changes.

### Bug Fixes

* **tenancy:** read the tenant owner from the database, locked during transfer ([999255b](https://github.com/apavliukov/laravel-authorization/commit/999255bc9e42d90cd60895552a882ed6b2f29d10))

## [1.3.0](https://github.com/apavliukov/laravel-authorization/compare/v1.2.2...v1.3.0) (2026-10-11)

### Highlights

Tenant ownership moves into the tenant table: set `tenancy.owner_role` (and keep `owner_key`, default `owner_id`) and the column becomes the only record of the owner, with the owner's role row derived from it — write it through `TenantMembership::transferOwnership()`, also right after creating a tenant. Editing the owner's membership, granting the owner role or deleting an owner throws `Tenancy\OwnershipException`; `tenancy.memberships => 'single'` refuses a second tenant with `Tenancy\MembershipPolicyException`; new `removeGlobal()`, `ownerOf()`, `isOwner()`, `HasMembers::owner()`, and `AuthorizationSeeder` / `authorization:repair-ownership` re-derive owner rows on every deploy. Upgrade: 1.2 apps need no change while `owner_role` is null; to opt in, add `owner_id` (NOT NULL, FK users, `restrictOnDelete`) with the backfill from `docs/ADOPTION.md` §3, then set `owner_role`. `TenantMembership::removeEverywhere()` and `ModelHasRolesQuery::detachAll()` are removed — remove memberships one tenant at a time.

### Features

* **tenancy:** tenant ownership, membership policy and removeGlobal ([6a48baa](https://github.com/apavliukov/laravel-authorization/commit/6a48baa48792adfc669e22352f34c6814326fc1b))

## [1.2.2](https://github.com/apavliukov/laravel-authorization/compare/v1.2.1...v1.2.2) (2026-10-10)

### Highlights

`$user->app_role` now reflects a role write on the same instance (the attribute no longer caches its first read). Role reads honour the user model's own guard, as Spatie does, so multi-guard apps keep the super-admin bypass without teams. The tenant resolver never activates a tenant whose row was deleted while memberships remained — those requests now resolve to no tenant and a stale session key is dropped. No config or wiring changes.

### Bug Fixes

* **tenancy:** close review findings on the 1.2 membership layer ([4b4a4ad](https://github.com/apavliukov/laravel-authorization/commit/4b4a4ad8678c26571d002b5fd35b1cb52171f204))

## [1.2.1](https://github.com/apavliukov/laravel-authorization/compare/v1.2.0...v1.2.1) (2026-10-10)

### Highlights

Identical to 1.2.0 in code. The `v1.2.0` tag was moved after publication and Packagist, which keeps stable versions immutable, stays pinned to the superseded commit `e8efc0a` — require `^1.2.1` instead.

## [1.2.0](https://github.com/apavliukov/laravel-authorization/compare/v1.1.1...v1.2.0) (2026-10-10)

### Highlights

Membership is now a role row in `model_has_roles` with `team_id` and nothing else: drop any `users.<tenant>_id` column or membership pivot. One `Tenancy\TenantResolver` replaces the `user` / `session` modes — session choice validated against membership (global super-admins may enter any existing tenant), else the user's sole tenant, else none; a user with several tenants and no choice gets no tenant, so apps with multi-membership need an organization picker behind `missing_tenant_redirect`. Remove `tenancy.resolver` and `tenancy.user_column` from the published config. New: `HasMembers` (`members()`) / `HasTenantMemberships` (`tenants()`) relations, `HasAppRole` (`app_role`, `is_platform_admin` — also without tenancy), `TenantMembership::assignGlobal / removeEverywhere / tenantIdsOf`, and the `accessPlatformAdmin` gate is registered unconditionally (delete hand-written `Gate::define`s). Every membership read is scoped to the default guard's roles. The tenancy conventions all apps converge on now live in `docs/ADOPTION.md` §3.

### ⚠ BREAKING CHANGES

* **tenancy:** FromUserResolver, FromSessionResolver and
  Contracts\TenantResolver are removed in favour of Tenancy\TenantResolver;
  config keys tenancy.resolver and tenancy.user_column are gone (session_key
  stays). Delete any users.<tenant>_id column or membership pivot and read
  membership through tenants() / members() / tenantIdsOf().

### Features

* **tenancy:** one membership source — roles in model_has_roles ([1f10940](https://github.com/apavliukov/laravel-authorization/commit/1f1094040284d64e0b85b17d4ba063d3ab588e47))

### Bug Fixes

* **tenancy:** scope membership reads to the default guard ([651e953](https://github.com/apavliukov/laravel-authorization/commit/651e953c388820baaa0be842c471ab09096c4af6))

## [1.1.1](https://github.com/apavliukov/laravel-authorization/compare/v1.1.0...v1.1.1) (2026-08-24)

### Highlights

`FromUserResolver` no longer throws under `Model::shouldBeStrict()` when the user instance was loaded without the tenant column. `CurrentTenant::enterBypass()` is now `@internal` — middleware only, use `withoutTenant()` in code. Teams without tenancy: the package registers no group middleware, wire `SetPermissionsTeam` in `bootstrap/app.php` yourself (documented in README and ADOPTION, pinned by a test).

### Bug Fixes

* **tenancy:** tolerate strict user models without the tenant column ([d256d51](https://github.com/apavliukov/laravel-authorization/commit/d256d5147180207b8048a30be798407fb0177f02))

## [1.1.0](https://github.com/apavliukov/laravel-authorization/compare/v1.0.1...v1.1.0) (2026-08-22)

### Highlights

Config-driven tenancy layer: publish `config/authorization.php` (`vendor:publish --tag=authorization-config`), set `tenancy.model`, and wire `SetCurrentTenant` in `bootstrap/app.php` — the provider no longer pushes any middleware onto `web`, so apps relying on the automatic `SetPermissionsTeam` registration must append it themselves. Tenant-owned models take `BelongsToTenant`; the scope is fail-closed and `Tenancy::withoutTenant()` / `forTenant()` are the only bypass. Tenant-level roles must be assigned inside the tenant (`TenantMembership`), and with teams on the super-admin bypass now honours only the global assignment — a tenant-scoped copy of a super-admin role no longer bypasses.

### Features

* add config-driven tenancy layer ([b8d3c4c](https://github.com/apavliukov/laravel-authorization/commit/b8d3c4c97bf7a780ac89d98a599509bdc0b4cc35))

## [1.0.1](https://github.com/apavliukov/laravel-authorization/compare/v1.0.0...v1.0.1) (2026-08-03)

### Highlights

Adds this CHANGELOG and the release tooling (commitlint, lefthook, commit-and-tag-version)
to the repository. No code changes.

## [1.0.0](https://github.com/apavliukov/laravel-authorization/compare/v0.8.0...v1.0.0) (2026-08-03)

### Highlights

First stable release — the public API of `0.8.0` is now frozen under semver.
The package ships the `laravel-authorization-development` skill via Boost resources
(`resources/boost/skills/`); consuming projects receive it in `.claude/skills/` on
`artisan boost:update`. No code changes and no upgrade steps from `0.8.0`.
