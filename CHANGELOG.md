# Changelog

All notable changes to this project will be documented in this file. See [commit-and-tag-version](https://github.com/absolute-version/commit-and-tag-version) for commit guidelines.

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
