# Package lifecycle snippets

> **Status**: Draft  
> **Updated**: 2026-05-23  
> **Owner**: Core  
> **Purpose:** Collect package discovery, validation, dry-run, review, execution, and action-log notes before the installer lifecycle is implemented.

## Overview

This page captures the current package lifecycle shape without pretending the installer is finished. Keep it as a source of working notes for themes, modules, cached imports, and future update packages.

## Current lifecycle sketch

```text
discover candidates
  -> parse manifest
  -> validate manifest namespace
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
| Theme | `themes/*/.manifest` | `THEME_*` |
| Module | `modules/*/.manifest` | `MODULE_*` |
| Import cache | `var/cache/$APP_ENV/imports/*/.manifest` | open/generic |

Discovery should remain cheap. It should not load package PHP classes, routes, migrations, services, or assets. Later lifecycle code can choose when a discovered package becomes active.

## Validation

Package validation is caller-defined. Core should provide the neutral primitives, while theme, module, import, update, or setup workflows choose required files and directories.

```php
$themeSpec = PackageSpec::create()
    ->requireFile('.manifest')
    ->requireDirectory('templates')
    ->requireDirectory('assets')
    ->withLintingChecks();

$result = (new PackageValidator())->validate($candidate, $themeSpec);
```

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
], targetPrefix: 'themes/demo');
```

## Open notes

- Dependency maps are intentionally deferred to installer/lifecycle routines.
- Package signatures and checksums belong to update/release workflows.
- Activation must be separate from discovery.
- Failed activation should roll back to the previous active theme or module state where practical.
- Module-owned data deletion needs explicit confirmation and action-log coverage.

## References

- [Core architecture snippets](core-architecture-snippets.md)
- [Theme and module developer guidelines](theme-module-developer-guidelines.md)
- [Plugin modules draft](../draft/0.2.x-PluginModules.md)
- [Theme engine draft](../draft/0.1.x-ThemeEngine.md)
