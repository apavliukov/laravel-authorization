# Changelog

All notable changes to this project will be documented in this file. See [commit-and-tag-version](https://github.com/absolute-version/commit-and-tag-version) for commit guidelines.

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
