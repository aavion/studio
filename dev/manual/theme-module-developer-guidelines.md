# Package developer guidelines (Developer Guide)

> **Status**: Draft  
> **Updated**: 2026-05-25  
> **Owner**: Core  
> **Purpose:** Draft guidance for developing packages, scoped themes, modules, providers, admin UI extensions, and first-party add-ons while the extension system is still being designed.  

## Overview

This guide captures the current direction for package development. It is intentionally marked as a draft: contracts, folder names, lifecycle hooks, and UI constraints may change while the CMS core is implemented.

Use this guide as a working reference when building the native system packages, admin UI, public themes, and first-party packages. Prefer the feature drafts when they contain more specific decisions.

## Extension principles

- Use Symfony-native integration points first: services, tagged services, Twig, routes, forms, validators, voters, EventDispatcher, Messenger, Doctrine migrations, AssetMapper, Tailwind, and translations.
- Keep packages inactive after discovery until an administrator explicitly activates them.
- Validate manifests before loading classes, routes, templates, migrations, permissions, assets, or providers.
- Keep extension points explicit:
  - **Observe:** react without changing the result.
  - **Extend:** add contributions through documented hooks or tagged services.
  - **Replace:** select one implementation through a contract, resolver, decoration, or configuration.
- Prefer resolver/provider contracts for one-active-provider behavior, such as captcha or editor providers.
- Keep package assets namespaced.
- Trigger Tailwind build and AssetMapper compilation after lifecycle changes with frontend contributions.
- Keep package secrets out of manifests, logs, screenshots, fixtures, and committed configuration.

## Package scopes

Packages live under `packages/<package-slug>/` and use `PACKAGE_*` manifest keys. `PACKAGE_SCOPE` is a DotEnv-style list such as `[frontend-theme, module]`, or a single value such as `module`.

Expected package shape:

```text
packages/<package-slug>/
  .manifest
  package.php
  src/
  templates/
  assets/
  config/
  migrations/
  translations/
```

Required manifest keys:

```text
PACKAGE_AUTHOR=Aavion
PACKAGE_NAME=Example Package
PACKAGE_VERSION=1.0.0
PACKAGE_SCOPE=[frontend-theme, module]
PACKAGE_DEPENDENCIES=[]
```

Current constraints:

- Allowed scopes start as `frontend-theme`, `backend-theme`, `system-template`, `module`, `captcha-provider`, and `editor-provider`.
- A package is always activated or deactivated as one unit. Scopes describe capabilities, not separately switchable sub-packages.
- Only one `frontend-theme`, one `backend-theme`, one `system-template`, and one provider package of each provider type may be active at the same time.
- Multiple `module` packages may be active at the same time.
- Activating a new single-active scope deactivates the previously active package for that scope. If that package also had module behavior, the module behavior is deactivated with it.
- Disabled packages must not contribute services, routes, templates, assets, migrations, permissions, providers, subscribers, or handlers.
- Packages may contribute routes, services, templates, assets, field types, editor actions, API resources, permissions, migrations, event subscribers, message handlers, or replaceable providers only through documented extension points.
- Package-owned domain data should prefer package-owned tables and migrations, using collision-resistant table names such as `pkg_<slug>_<table>`.
- Packages with frontend or admin assets must participate in the asset rebuild workflow.
- Packages need uninstall/remove behavior, including explicit confirmation before deleting package-owned data.
- Failed activation or deactivation should roll back to the previous state where practical.

`package.php` is optional. It must never be included during discovery and should only be loaded after a package is valid and active. Packages are trusted code; only administrators may install them. A package should use a package-owned root namespace derived from or declared for the package slug.

Package assets must be self-contained. Packages should vendor their external dependencies inside their own package directory instead of requiring the project importmap to manage third-party dependency lifecycles across packages. Active package CSS and JavaScript are aggregated through the generated package asset registries; packages should not expect templates to add arbitrary direct `<link>` or `<script>` tags for package-level assets. Static assets such as images, fonts, videos, and SVGs should be referenced from package CSS, JavaScript, or templates after the lifecycle mirrors them into the AssetMapper-visible package path.

Template paths use logical Twig namespaces. Frontend packages target `templates/frontend/**` and reference templates as `@frontend/...`. Backend packages target `templates/backend/**` and reference templates as `@backend/...`. Shared fallbacks use `@root/...`; packages may reference root templates, but only packages with `system-template` scope may override root-level shared files such as `base.html.twig` or `macros/**`.

## Admin UI and UX guidelines

The admin interface should feel quiet, dense, predictable, and work-focused. Avoid marketing-style layouts, oversized hero sections, decorative page cards, and one-off interaction patterns.

Use these UI rules as the current baseline:

- Prefer clear tables, forms, filters, status indicators, tabs, dialogs, and action logs.
- Keep cards for repeated items, modals, and genuinely framed tools. Do not nest cards inside cards.
- Keep page sections unframed where possible.
- Use icon buttons for common tool actions where the icon is familiar; add tooltips for less obvious actions.
- Use text buttons for clear commands and destructive confirmations.
- Use toggles, checkboxes, segmented controls, sliders, selects, and menus according to the input type.
- Keep text inside buttons and compact controls short enough to fit at mobile and desktop widths.
- Use translated labels, help text, empty states, validation messages, flash messages, and action labels.
- Preserve submitted input on validation failure.
- Show destructive actions behind confirmation and review screens where needed.
- Use the shared action-log pattern for setup, imports, backups, updates, asset rebuilds, package lifecycle changes, and other long-running operations.
- Keep navigation and dashboard contributions permission-aware.
- Meet baseline accessibility expectations: semantic landmarks, keyboard navigation, visible focus, color contrast, labels, error association, and reduced-motion safety.

## Provider Packages

Provider packages should implement a documented contract and be selectable through core-owned configuration.

Examples:

- Captcha providers such as IconCaptcha.
- Editor providers such as a future TinyMCE module.
- Search, storage, export, or media adapters.

Provider packages should define required capabilities before they are allowed to replace a default. For editor providers, this may include Markdown/rich-text behavior, resolver-token insertion, autocomplete, validation feedback, diff integration, and asset lifecycle support.

## Testing and validation

Package work should include tests for:

- manifest parsing and validation;
- inactive discovery state;
- activation behavior;
- rollback on failed lifecycle actions;
- asset rebuild triggers and command order;
- route, service, template, provider, and permission contributions;
- ACL and permission-aware UI visibility;
- translation-key coverage for user-facing strings;
- uninstall/remove behavior where applicable.

Run relevant verification commands once the implementation exists:

```bash
php bin/console lint:container
php bin/console studio:assets:rebuild
php bin/phpunit
php .codex/compare_translations.php
```

## References

- [Feature draft index](../draft/README.md)
- [Core architecture draft](../draft/0.1.x-CoreArchitecture.md)
- [Theme engine draft](../draft/0.1.x-ThemeEngine.md)
- [Package modules and providers draft](../draft/0.2.x-PluginModules.md)
- [System theme and design system draft](../draft/0.1.x-SystemThemeDesignSystem.md)
- [Operational admin workflows draft](../draft/0.4.x-OperationalAdminWorkflows.md)
