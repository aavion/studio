# Test fixture snippets

> **Status**: Draft  
> **Updated**: 2026-05-23  
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

`App\Tests\Support\TestSuiteLifecycle` is wired from `tests/bootstrap.php`. It currently reserves a shared suite temporary root at `sys_get_temp_dir().'/studio-test-suite'`, runs a no-op `initialize()` step, and registers shutdown cleanup for that root.

The lifecycle is intentionally underused for now. Keep it visible because it is the right home for later shared test setup such as:

- generated demo databases;
- generated package caches;
- copied fixture repositories;
- expensive integration fixtures;
- suite-wide cleanup that should run after PHPUnit exits.

Prefer per-test temporary directories through `FilesystemTestHelper` until setup becomes expensive or shared state is clearly useful. Do not put hidden test requirements into `TestSuiteLifecycle` without documenting them here.

## References

- [Core architecture snippets](core-architecture-snippets.md)
- [Package lifecycle snippets](package-lifecycle-snippets.md)
- `tests/Core/Package/PackageFixtureTest.php`
- `tests/Support/TestSuiteLifecycle.php`
- `tests/bootstrap.php`
