# Package lifecycle snippets

> **Status**: Draft  
> **Updated**: 2026-05-27  
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

A full discovery run is an explicit lifecycle operation, not request-time work. It should be triggered by an administrator refresh action, installer completion, setup task, scheduler task, or future update-check workflow. The product-level operation covers manifest scanning, `PackageValidator` validation, and registry reconciliation, even if the underlying services stay split for testability.

Use `App\Core\Package\PackageDiscoveryRunner` for deterministic registry refreshes. The Admin UI starts that work through the live Operations/ActionLog layer when JavaScript is available and falls back to an immediate POST run for non-JavaScript submissions. Use `App\Core\Package\PackageDiscoveryDispatcher` for installer completion, scheduler tasks, update checks, and other deferred tooling. It queues `PackageDiscoveryMessage` through Symfony Messenger and returns a localized `WorkflowResult` immediately. The message handler calls the same runner, which records the trigger context, calls `PackageDiscovery`, stops before registry mutation when discovery itself is invalid, catches unexpected exceptions as operation issues, and delegates successful package candidates to `PackageRegistryHandler`.

Manual CLI runs use `php bin/console studio:packages:discover`; add `--json` for machine-readable output and `--trigger=<name>` when a caller needs to identify the source. The command queues discovery by default. Use `--run-now` only for deliberate recovery or local maintenance where synchronous execution is acceptable.

Deferred Messenger work is drained opportunistically after HTTP responses through `App\Core\Messenger\DeferredMessengerDrainSubscriber`. It checks for due `async` transport rows after the main request has terminated, starts one detached `messenger:consume async` worker when work is pending, and uses a short environment-scoped cache lock to avoid parallel request-triggered workers. Missing database connections or missing `messenger_messages` tables are treated as a no-op so first-run setup and recovery routes stay graceful.

Package discovery is intentionally not registered as a Symfony cache warmer. Cold container builds must not touch Messenger or database-backed package state before setup has established the application schema. Setup runs package discovery after migrations and initial data seeding, then runs `studio:assets:rebuild --trigger=setup --json` so package-aware translation aggregation happens after discovery and non-blocking rebuild warnings can be surfaced in the setup action log. A future scheduler job should call `studio:packages:discover` periodically; installer completion and Admin UI refresh actions already provide additional explicit trigger points.

Repository demo packages live under `packages/demo-*`. They are intentionally small and inactive after discovery; use them to exercise package discovery, registry views, scoped assets, provider paths, package runtime contributions, and lifecycle UI flows. Demo packages may include tiny `package.php` contribution loaders, but they execute only after explicit activation.

Package Twig validation checks syntax while accepting runtime filters, functions, and tests as extension placeholders. Availability of Symfony, Studio, theme, or package-provided Twig extensions is verified by render/runtime coverage instead of discovery-time linting.

Required package manifest keys:

| Key | Purpose |
|-----|---------|
| `PACKAGE_AUTHOR` | Human-readable author or vendor. |
| `PACKAGE_SLUG` | Stable package identifier and target folder name under `packages/`. |
| `PACKAGE_NAME` | Human-readable package name. |
| `PACKAGE_VERSION` | Package version. |
| `PACKAGE_SCOPE` | One or more scopes, for example `[frontend-theme, module]`. |
| `PACKAGE_DEPENDENCIES` | Dependency list, empty when no dependency is required. |

Optional keys include `PACKAGE_SOURCE`, `PACKAGE_CHANNEL`, `PACKAGE_IMAGE`, `PACKAGE_NAMESPACE`, `PACKAGE_DESCRIPTION`, `PACKAGE_LICENSE`, and `PACKAGE_HOMEPAGE`. `PACKAGE_SOURCE` should point to the repository or release source root without a branch suffix, while `PACKAGE_CHANNEL` names the branch or channel; admin UI links may combine both values into a branch-specific URL where the source host supports it. Dependency entries use a compact JSON-like list of `[package, minimum-version]` pairs such as `[["system","0.1.0"],["demo-frontend-theme","0.1.1"]]`; malformed non-empty dependency declarations are validation errors. The virtual `system` package is treated as an active dependency backed by the root `.manifest`; real package dependencies still resolve against the persistent package registry.

The ZIP installer stages uploads under `var/cache/{APP_ENV}/package-installs`, verifies the manifest and package lint rules, then pauses the live operation with a review-required confirmation before copying files. `PACKAGE_SLUG` defines the target folder. If that package already exists, the apply step removes the existing package folder first, preserving whether it was active so activation can be restored after discovery. Update execution beyond ZIP uploads is deferred: a later updater should compare registered versions with manifest source metadata and use a narrow Git-backed stub for package or system updates.

Current allowed scopes are `frontend-theme`, `backend-theme`, `system-template`, `module`, `captcha-provider`, and `editor-provider`. Frontend themes, backend themes, system-template packages, and provider scopes are single-active scopes: activating a new package with the same single-active scope deactivates the previously active package. Module packages may be active in parallel.

Deactivation cascades through active reverse dependencies. If an active package depends on a package being deactivated, it is deactivated first; this applies to explicit deactivation and to implicit single-active scope conflicts during activation. Activation still resolves dependencies in the opposite direction and activates inactive dependencies with the requested package.

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

Active package translations are aggregated during the same rebuild queue. A package may ship source catalogues as `languages/<locale>/*.yaml`; if it ships any translation source, it must include at least one configured fallback catalogue under `languages/<fallback-locale>/` or the matching primary language directory. Package-owned keys must stay below `pkg.<package-slug>.*`. Inactive package language files are not aggregated, so disabled package copy cannot affect runtime translation fallback.

Template overrides are scope-bound. Any valid package scope may ship additive frontend and backend views under `templates/frontend/**` or `templates/backend/**`; only `frontend-theme` and `backend-theme` paths are searched before native templates and can override matching area templates. Module and provider package paths are searched after native templates, so they can provide package-specific views without replacing core UI. `system-template` may override root-level shared templates such as `templates/base.html.twig` and `templates/macros/core/**`. Packages may reference root templates through `@root/...` for fallback behavior even without `system-template`, but they must not replace root templates unless the scope is present. Package-owned macros are additive and belong under `templates/macros/{package-slug}/**`.

Provider package templates use the shared `@provider` namespace. A package with `PACKAGE_SCOPE=captcha-provider` may ship `templates/provider/captcha/**`; a package with `PACKAGE_SCOPE=editor-provider` may ship `templates/provider/editor/**`. Active provider package paths are searched before native `templates/provider/**`, so native provider templates remain the base implementation whenever no matching provider package is active.

`App\Core\Package\ActivePackageProvider` is the central gate for package loaders. Twig namespace registration and package asset sync already use this boundary, and future service, route, migration, provider, and scheduler loaders should use it instead of querying `extension_package` directly so only active real filesystem packages under `packages/` can contribute runtime behavior. Virtual records such as the native `system` fallback are intentionally excluded from this gate.

`PackagePhpLoader` loads optional active package `package.php` files at runtime. Discovery and registry sync never include package PHP. The package loader may bootstrap a package-owned namespace, return a callable, return contribution DTOs/providers, or return a `PackageContributions` builder for a readable grouped contribution list. Supported direct contributions currently include `StaticViewInjection`, `ConfigurableStaticViewInjectionSet`, `DynamicViewInjection`, `PackageSettingDefinition`, and scheduler task definitions; provider objects implementing the matching provider interfaces are also accepted and evaluated during package loading so provider failures keep package attribution. Static injections own menu-aware system views and route targets by surface, configurable static route sets read their stable parent path from package settings with a declared default, and dynamic injections own content-route slots or variant routes through declarative filters such as real-content-only or schema matching. Hard failures are converted into structured lifecycle diagnostics, mark the package `faulty`, deactivate active reverse dependents with explicit lifecycle messages, and queue a deferred asset rebuild so the same broken package is excluded by the active gate and stale active assets can be cleaned up. Returned iterables are staged before registry mutation, so unsupported later items do not leave partial runtime contributions active for the current request.

Simple package settings are registered through `PackageSettingProviderInterface`. A package setting definition provides package name, key, label, default value, value type, optional input type, description, option list, validation metadata, arbitrary metadata, and sort order. The core `PackageSettingRegistry` exposes definitions only for active packages, and `PackageSettings` stores values in `package_setting_entry` instead of global configuration. Admin Settings owns the generic package settings overview at `/admin/settings/packages` and package-specific pages below `/admin/settings/packages/{package-slug}`; packages that need complex workflows should register their own views outside the settings tree.

`faulty` is a registry exit state, not a hidden archived state. During a later automatic discovery run, an unchanged faulty package stays `faulty` and is not revalidated just because discovery ran again. This avoids rebuild/cache-warmup/discovery loops without a clear operator action. Revalidation happens when discovery sees a meaningful package change such as a new manifest version or changed package path, or when an administrator explicitly resets or repairs the lifecycle state. Even after a successful revalidation, the package returns to `inactive`, not `active`, so dependency checks, conflict handling, and rebuild logging remain auditable.

`PackageFaultResetter` is the non-destructive repair path for faulty packages. It rediscovers only the registered package directory, validates the current manifest and package files, preserves compact fault metadata under `last_fault`, and resets the package from `faulty` to `inactive` only after validation succeeds. It does not delete package data, remove the package folder, purge registry rows, or activate the package. Use purge only for explicit data cleanup after removal.

Asset ordering should be deterministic: native system assets first, active module/provider package assets next, active frontend theme package assets next, and active backend theme package assets last so scoped theme overrides win where CSS/JS order matters. Project-local or entity-local assets remain closer to the rendered element and may be more specific by design.

Use `php bin/console studio:assets:rebuild` as the global rebuild operation after package activation, deactivation, update, uninstall, setup, or manual admin recovery. Add `--queue --trigger=<name>` when the caller only needs to enqueue the rebuild through Messenger. Setup runs the command synchronously in its own subprocess so memory and execution time are isolated from the setup runner process. Lifecycle services trigger one rebuild after all package state changes in the operation have been flushed, not once per package. Runtime package exits such as hook failures, missing active packages during discovery, or PHP loader failures queue the same rebuild through Messenger so stale active assets/templates/translations are cleaned up without running Tailwind and cache clearing inside the current request. If the rebuild message cannot be queued during registry sync, the registry handler runs one synchronous fallback rebuild and records the dispatch failure in the result context/messages. It should run as an ActionLog-backed operation with persisted step entries and progress metadata. The package mirror and registry rewrite step runs before Tailwind; translation aggregation writes `translations/runtime/{APP_ENV}/messages.{locale}.yaml` before `cache:clear`. `cache:clear` runs last so the live operation UI is not invalidated before the rebuild has already produced mirrored assets, registries, Tailwind output, active translation catalogues, and production asset-map output.

Live package actions should enter the Operations/ActionLog layer through tagged `LiveOperationQueueProviderInterface` providers. A provider owns one operation key, validates its serialized payload, builds an `ActionQueue`, and keeps destructive confirmation or dependency review outside the apply queue unless it intentionally returns a review-required result. If review is needed during a live flow, the provider should emit a user-facing action-required prompt and a safe continuation descriptor; the follow-up apply step is a new live operation, not a suspended process.

`PackageRuntimeFailureSubscriber` listens to public hook failure diagnostics and delegates to `PackageRuntimeFailureHandler`. If the failing package can be identified and is active, the handler records runtime-failure metadata, marks the package `faulty`, deactivates active reverse dependents with a `package.lifecycle.dependent_deactivated` message, and queues a deferred asset rebuild. If ownership is unknown, the hook failure remains a structured issue without changing package state.

`PackageRemover` prepares the removal boundary for later Admin UI and installer flows. Removal deactivates an active package first, deletes the package directory, marks the registry row as `removed`, and can trigger the same package-aware asset rebuild. Purge is separate and only available for already removed packages: it calls `PackageLifecycleCleanupRunnerInterface` for package-owned cleanup and then deletes the registry row. The default cleanup runner removes package-scoped settings; package-owned migrations and broader data deletion are still deferred. If the package directory still exists after purge, later discovery can register it again.

## Open notes

- Dependency maps are intentionally deferred to installer/lifecycle routines.
- Discovery does not run during normal requests.
- Automatic discovery is limited to fresh container boot or cache rebuild flows.
- Manual discovery triggers include Admin UI refresh, installer completion, scheduler task, and future update-check workflows.
- Package signatures and checksums belong to update/release workflows.
- Activation must be separate from discovery.
- Failed activation should roll back to the previous active package state where practical.
- Package-owned data deletion needs explicit confirmation and action-log coverage before the cleanup runner performs destructive work.
- Package-owned database tables should use collision-resistant names such as `pkg_<slug>_<table>`.
- Deactivation must not drop data. Uninstall may offer data removal through a package purge routine.
- Theme, system-template, and provider scopes are single-active; module scopes are many-active.

## References

- [Core architecture snippets](core-architecture-snippets.md)
- [Package developer guidelines](theme-module-developer-guidelines.md)
- [Package modules and providers draft](../draft/0.2.x-PluginModules.md)
- [Theme engine draft](../draft/0.1.x-ThemeEngine.md)
