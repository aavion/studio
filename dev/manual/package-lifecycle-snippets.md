# Package lifecycle snippets

> **Status**: Draft  
> **Updated**: 2026-05-25  
> **Owner**: Core  
> **Purpose:** Collect package discovery, validation, dry-run, review, execution, and action-log notes before the installer lifecycle is implemented.  

## Overview

This page captures the current package lifecycle shape without pretending the installer is finished. Keep it as a source of working notes for installable packages, cached imports, and future update packages.

Packages are the only installable extension unit. Themes, modules, captcha providers, editor providers, and future extension types are package scopes, not separate top-level package systems. A package is active or inactive as one unit; if a package has both `frontend-theme` and `module` scopes, switching away from that theme deactivates the whole package instead of trying to split its theme and module parts.

## Current lifecycle sketch

```text
discover candidates
  -> parse manifest
  -> validate PACKAGE_* manifest keys
  -> validate package shape
  -> run optional lint checks
  -> inspect features
  -> build dry-run/action queue
  -> present review and diffs
  -> execute queue
  -> persist action log
```

## Discovery

Current default package roots:

| Source | Path | Manifest namespace |
|--------|------|--------------------|
| App | `./.manifest` | `APP_*` |
| Package | `packages/*/.manifest` | `PACKAGE_*` |
| Import cache | `var/cache/$APP_ENV/imports/*/.manifest` | open/generic |

Discovery should remain cheap. It should not load package PHP classes, routes, migrations, services, or assets. Later lifecycle code can choose when a discovered package becomes active.

Required package manifest keys:

| Key | Purpose |
|-----|---------|
| `PACKAGE_AUTHOR` | Human-readable author or vendor. |
| `PACKAGE_NAME` | Human-readable package name. |
| `PACKAGE_VERSION` | Package version. |
| `PACKAGE_SCOPE` | One or more scopes, for example `[frontend-theme, module]`. |
| `PACKAGE_DEPENDENCIES` | Dependency list, empty when no dependency is required. |

Optional keys include `PACKAGE_SOURCE`, `PACKAGE_CHANNEL`, `PACKAGE_IMAGE`, `PACKAGE_NAMESPACE`, `PACKAGE_DESCRIPTION`, `PACKAGE_LICENSE`, and `PACKAGE_HOMEPAGE`.

Current allowed scopes are `frontend-theme`, `backend-theme`, `system-template`, `module`, `captcha-provider`, and `editor-provider`. Frontend themes, backend themes, system-template packages, and provider scopes are single-active scopes: activating a new package with the same single-active scope deactivates the previously active package. Module packages may be active in parallel.

## Validation

Package validation is caller-defined. Core should provide the neutral primitives, while package, import, update, or setup workflows choose required files and directories.

```php
$packageSpec = PackageSpec::create()
    ->requireFile('.manifest')
    ->withLintingChecks();

$result = (new PackageValidator())->validate($candidate, $packageSpec);
```

`package.php` is optional and must never be included during discovery. It may be included only after validation and activation. Packages are trusted code, so only administrators should install them. Package PHP classes should live below a package-owned root namespace derived from or declared for the package slug to avoid collisions.

## Feature inspection

Package inspection currently reports:

- templates;
- assets;
- any PHP files;
- `src/` PHP files;
- Twig files;
- JSON, YAML, CSS, and JavaScript files.

Use this for debug UI, import review, package detail screens, and installer preflight summaries. Do not use feature inspection as permission to auto-load code.

## Planning and execution

`PackageOperationPlanner` currently maps selected files into deterministic copy actions. It does not decide package type, dependencies, activation, rollback, migrations, or permissions.

```php
$planner = new PackageOperationPlanner();
$queueResult = $planner->copyFiles($candidate, $projectDir, [
    'templates/base.html.twig',
    'assets/app.css',
], targetPrefix: 'packages/demo');
```

Package assets must be self-contained. Packages should vendor any external JavaScript or CSS dependencies they need instead of making the project inject third-party dependency declarations into the global importmap.

Active package assets are exposed through generated registries rather than direct runtime discovery. The lifecycle mirrors active package assets into `assets/packages/<package-slug>/` and rewrites stable CSS/JS registry files under `assets/styles/packages/` and `assets/js/packages/`. CSS registries may include Tailwind `@source` directives for active package templates and `@import` directives for mirrored active package CSS. JavaScript registries use static ESM imports for mirrored active package JavaScript.

Static package assets such as images, fonts, SVGs, videos, and vendored dependency files are mirrored but not registered as standalone CSS/JS entries. They are served by AssetMapper only when referenced through mirrored package CSS, JavaScript, or templates. Source paths under `packages/<slug>/...` must not leak into public output.

Template overrides are scope-bound. `frontend-theme` may override `templates/frontend/**`; `backend-theme` may override `templates/backend/**`; `system-template` may override root-level shared templates such as `templates/base.html.twig` and `templates/macros/**`. Packages may reference root templates through `@root/...` for fallback behavior even without `system-template`, but they must not replace root templates unless the scope is present.

Asset ordering should be deterministic: native system assets first, active module/provider package assets next, active frontend theme package assets next, and active backend theme package assets last so scoped theme overrides win where CSS/JS order matters. Project-local or entity-local assets remain closer to the rendered element and may be more specific by design.

Use `php bin/console studio:assets:rebuild` as the global rebuild operation after package activation, deactivation, update, uninstall, or manual admin recovery. It should run as an ActionLog-backed operation with persisted step entries and progress metadata. The package mirror and registry rewrite step runs before Tailwind. `cache:clear` runs last so the live operation UI is not invalidated before the rebuild has already produced mirrored assets, registries, Tailwind output, and production asset-map output.

## Open notes

- Dependency maps are intentionally deferred to installer/lifecycle routines.
- Package signatures and checksums belong to update/release workflows.
- Activation must be separate from discovery.
- Failed activation should roll back to the previous active package state where practical.
- Package-owned data deletion needs explicit confirmation and action-log coverage.
- Package-owned database tables should use collision-resistant names such as `pkg_<slug>_<table>`.
- Deactivation must not drop data. Uninstall may offer data removal through a package purge routine.
- Theme, system-template, and provider scopes are single-active; module scopes are many-active.

## References

- [Core architecture snippets](core-architecture-snippets.md)
- [Package developer guidelines](theme-module-developer-guidelines.md)
- [Package modules and providers draft](../draft/0.2.x-PluginModules.md)
- [Theme engine draft](../draft/0.1.x-ThemeEngine.md)
