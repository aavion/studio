# Theme and module developer guidelines (Developer Guide)

> **Status**: Draft  
> **Updated**: 2026-05-24  
> **Owner**: Core  
> **Purpose:** Draft guidance for developing themes, plugin modules, admin UI extensions, and first-party add-ons while the extension system is still being designed.  

## Overview

This guide captures the current direction for theme and module development. It is intentionally marked as a draft: contracts, folder names, lifecycle hooks, and UI constraints may change while the CMS core is implemented.

Use this guide as a working reference when building the system theme, admin UI, public themes, and first first-party modules. Prefer the feature drafts when they contain more specific decisions.

## Extension principles

- Use Symfony-native integration points first: services, tagged services, Twig, routes, forms, validators, voters, EventDispatcher, Messenger, Doctrine migrations, AssetMapper, Tailwind, and translations.
- Keep modules and themes inactive after discovery until an administrator explicitly activates or enables them.
- Validate manifests before loading classes, routes, templates, migrations, permissions, assets, or providers.
- Keep extension points explicit:
  - **Observe:** react without changing the result.
  - **Extend:** add contributions through documented hooks or tagged services.
  - **Replace:** select one implementation through a contract, resolver, decoration, or configuration.
- Prefer resolver/provider contracts for one-active-provider behavior, such as captcha or editor providers.
- Keep module and theme assets namespaced.
- Trigger Tailwind build and AssetMapper compilation after lifecycle changes with frontend contributions.
- Keep package secrets out of manifests, logs, screenshots, fixtures, and committed configuration.

## Theme guidelines

Themes should render public-facing project content. They should not override system/admin/editor/setup templates in early releases. If a public theme package contains those paths, the theme engine should ignore them and report diagnostics. Later manifest capabilities may deliberately allow dedicated system UI themes, but normal public themes stay limited to public rendering.

Expected package shape:

```text
themes/<theme-name>/
  .manifest
  src/
  templates/
  assets/
  translations/
```

Current constraints:

- Themes are discovered as inactive/available and require explicit activation.
- Theme PHP classes may define their own namespace and integrate only through documented hooks, tagged services, or Twig extensions.
- Template overrides follow the original folder structure and only affect allowed public template areas.
- Themes may be partial. Missing templates and assets fall back to the native public baseline shipped with the project.
- Theme macro/function files are aggregated under a provider namespace. They must not replace native macro namespaces required by fallback templates.
- The active theme provides outer layout and generic fieldset fallback rendering.
- Database-backed schema Twig is separate from theme template resolution.
- Failed activation should roll back to the previous active theme where practical.

## Module guidelines

Modules should add optional behavior without forcing the core to anticipate every future use case.

Expected package shape:

```text
modules/<module-name>/
  .manifest
  src/
  config/
  templates/
  assets/
  translations/
  migrations/
```

Current constraints:

- Modules are discovered as inactive/available and require explicit enablement.
- Disabled modules must not contribute services, routes, templates, assets, migrations, permissions, providers, subscribers, or handlers.
- Modules may contribute routes, services, templates, assets, field types, editor actions, API resources, permissions, migrations, event subscribers, message handlers, or replaceable providers only through documented extension points.
- Modules may extend admin, editor, setup, operation, or system UI through documented extension points, but they should not rely on unrestricted template overrides.
- Module-owned domain data should prefer module-owned tables and migrations.
- Modules with frontend or admin assets must participate in the asset rebuild workflow.
- Modules need uninstall/remove behavior, including explicit confirmation before deleting module-owned data.
- Failed enablement or disablement should roll back to the previous state where practical.

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
- Use the shared action-log pattern for setup, imports, backups, updates, asset rebuilds, module/theme lifecycle changes, and other long-running operations.
- Keep navigation and dashboard contributions permission-aware.
- Meet baseline accessibility expectations: semantic landmarks, keyboard navigation, visible focus, color contrast, labels, error association, and reduced-motion safety.

## Provider and editor modules

Provider modules should implement a documented contract and be selectable through core-owned configuration.

Examples:

- Captcha providers such as IconCaptcha.
- Editor providers such as a future TinyMCE module.
- Search, storage, export, or media adapters.

Provider modules should define required capabilities before they are allowed to replace a default. For editor providers, this may include Markdown/rich-text behavior, resolver-token insertion, autocomplete, validation feedback, diff integration, and asset lifecycle support.

## Testing and validation

Theme and module work should include tests for:

- manifest parsing and validation;
- inactive discovery state;
- activation or enablement behavior;
- rollback on failed lifecycle actions;
- asset rebuild triggers and command order;
- route, service, template, provider, and permission contributions;
- ACL and permission-aware UI visibility;
- translation-key coverage for user-facing strings;
- uninstall/remove behavior where applicable.

Run relevant verification commands once the implementation exists:

```bash
php bin/console lint:container
php bin/console tailwind:build
php bin/console asset-map:compile
php bin/phpunit
php .codex/compare_translations.php
```

## References

- [Feature draft index](../draft/README.md)
- [Core architecture draft](../draft/0.1.x-CoreArchitecture.md)
- [Theme engine draft](../draft/0.1.x-ThemeEngine.md)
- [Plugin modules draft](../draft/0.2.x-PluginModules.md)
- [System theme and design system draft](../draft/0.1.x-SystemThemeDesignSystem.md)
- [Operational admin workflows draft](../draft/0.4.x-OperationalAdminWorkflows.md)
