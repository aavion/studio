# Test fixture snippets

> **Status**: Draft  
> **Updated**: 2026-05-24  
> **Owner**: Core  
> **Purpose:** Track reusable test fixtures, intentionally invalid packages, and when to prefer repository fixtures over temporary test data.  

## Overview

Fixtures should make future tests cheaper to write without hiding behavior. Keep reusable fixtures small, deterministic, and representative of real package shapes.

## Valid package fixtures

Valid package fixtures live under `tests/Fixtures/packages/` and mirror the standard discovery paths:

```text
tests/Fixtures/packages/
  .manifest
  themes/demo-theme/
  modules/demo-module/
  var/cache/test/imports/demo-import/
```

Current intended use:

- package discovery smoke tests;
- package validation and feature inspection;
- preflight linting happy paths;
- package operation planning;
- later installer and dry-run examples.

## Invalid package fixtures

Invalid fixtures live under `tests/Fixtures/packages-invalid/`:

| Fixture | Purpose |
|---------|---------|
| `broken-manifest` | Manifest parser diagnostics. |
| `missing-theme-files` | Missing required file/directory diagnostics. |
| `broken-lint` | PHP, Twig, JSON, YAML, CSS, and JavaScript lint diagnostics. |

Do not add these folders to default discovery tests unless the test explicitly expects an invalid result.

## Temporary fixtures

Use temporary directories when a test needs:

- destructive filesystem operations;
- symlinks;
- unusual permission behavior;
- path traversal attempts;
- isolated setup or cleanup behavior;
- generated files that should not be committed.

Use `App\Tests\Support\FilesystemTestHelper` for temporary directories, fixture paths, symlink skip handling, file writing, and cleanup. Temporary directories created through this helper live under the `TestSuiteLifecycle` suite root so shutdown cleanup can remove leftovers if a test fails before its own `tearDown()` runs.

## Lifecycle hooks

`App\Tests\Support\TestSuiteLifecycle` is wired from `tests/bootstrap.php`. It reserves a shared suite temporary root at `sys_get_temp_dir().'/studio-test-suite'`, initializes the test database, and registers shutdown cleanup for that root.

The lifecycle initializes `env:test` directly. Before PHPUnit runs, it acquires a non-blocking suite lock, clears `var/test`, applies the current Doctrine baseline migration to `var/test/test.db`, and calls `App\Tests\Support\TestDatabaseSeeder` to load deterministic demo data. Concurrent PHPUnit processes are rejected with a clear message because they would otherwise mutate the shared SQLite test database. This keeps the test suite independent from `bin/setup`.

The current database seed includes:

- global content configuration defaults;
- preset ACL groups for registered, editor, manager, and admin access levels; public access remains level `0` without a persisted group;
- a deterministic admin account (`admin` with the current `APP_SECRET` as password) plus read-write, read-only, and revoked API keys;
- active `static_page` and `article` schemas;
- published home, about, and article content with active revisions and localized field values;
- a main navigation menu pointing to the seeded content.

Keep lifecycle setup visible because it is the right home for shared test setup such as:

- generated demo databases;
- generated package caches;
- copied fixture repositories;
- expensive integration fixtures;
- suite-wide cleanup that should run after PHPUnit exits.

Prefer per-test temporary directories through `FilesystemTestHelper` for filesystem state. Do not put hidden test requirements into `TestSuiteLifecycle` without documenting them here and covering them with operations tests.

## References

- [Core architecture snippets](core-architecture-snippets.md)
- [Package lifecycle snippets](package-lifecycle-snippets.md)
- `tests/Core/Package/PackageFixtureTest.php`
- `tests/Support/TestSuiteLifecycle.php`
- `tests/bootstrap.php`
