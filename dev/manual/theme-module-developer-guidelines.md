# Package developer guidelines (Developer Guide)

> **Status**: Draft  
> **Updated**: 2026-05-26  
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
  languages/
```

Required manifest keys:

```text
PACKAGE_AUTHOR=Aavion
PACKAGE_SLUG=example-package
PACKAGE_NAME=Example Package
PACKAGE_VERSION=1.0.0
PACKAGE_SCOPE=[frontend-theme, module]
PACKAGE_DEPENDENCIES=[]
```

Optional source metadata stays split: `PACKAGE_SOURCE` points to the repository or release source root, and `PACKAGE_CHANNEL` identifies the branch or channel. The admin UI may turn those two values into a branch-specific link, but update tooling must still be able to reconstruct clone/fetch targets from the raw manifest values.

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
- Installable packages are checked against core-owned package policies before activation or ZIP apply. The file policy blocks clearly unsafe payload paths such as `.env*`, VCS metadata, `bin/`, `node_modules/`, `public/`, `var/`, `vendor/`, and root `composer.json`/`composer.lock`; it warns about development-only payloads such as `docs/`, `tests/`, `.github/`, `.idea/`, or `.vscode/`. Package-local `config/` remains allowed for package-owned data until a stricter package configuration contract is decided. The PHP capability policy blocks direct filesystem, process, network, request-context, and environment access from package PHP; packages should request work through documented extension points instead.

`package.php` is optional. It must never be included during discovery and should only be loaded after a package is valid and active. Packages are trusted code; only administrators may install them. A package should use a package-owned root namespace derived from or declared for the package slug.

When `PACKAGE_NAMESPACE` is declared, PHP files below `src/` must use that namespace or one of its child namespaces. The active runtime loader includes only `package.php`; that file may return simple contribution DTOs/providers or a callable that returns them, but it must not directly include files, read or write files, spawn processes, open network sockets, read raw environment/request globals, or bypass package extension points. For multiple contributions, prefer `App\Core\Package\PackageContributions::create()` so the package entry point remains readable and each contribution type is named. Supported direct contributions currently include static view injections, configurable static route sets, dynamic view injections, package setting definitions, and scheduler task definitions. Loader failures are caught by the lifecycle layer, recorded as structured diagnostics, and mark the package `faulty` so a broken active package does not keep breaking requests. Contribution iterables are staged before registry mutation, so one unsupported item rejects the full package contribution for the current request.

Package assets must be self-contained. Packages should vendor their external dependencies inside their own package directory instead of requiring the project importmap to manage third-party dependency lifecycles across packages. Active package CSS and JavaScript are aggregated through the generated package asset registries; packages should not expect templates to add arbitrary direct `<link>` or `<script>` tags for package-level assets. Static assets such as images, fonts, videos, and SVGs should be referenced from package CSS, JavaScript, or templates after the lifecycle mirrors them into the AssetMapper-visible package path.

Area-specific package assets follow the same boundary as template namespaces. A package with `frontend-theme` should put frontend-only entrypoints under `assets/frontend/**`; a package with `backend-theme` should put backend-only entrypoints under `assets/backend/**`. Root-level package assets and other package asset subdirectories are shared/global and enter the extension registry only when the package also declares a global scope such as `module`, `captcha-provider`, `editor-provider`, or `system-template`.

Package asset registries control deterministic rebuild order, but Tailwind currently emits one application stylesheet. CSS that belongs to one rendered area should therefore stay scoped to that area's root class, such as `.system-frontend` or `.system-backend`, unless the package intentionally contributes global module/provider styling. Root/shared templates use `{system|package-slug}-{class}` selectors, provider templates use `{system|package-slug}-{provider-scope}-{class}`, and frontend/backend templates use `{system|package-slug}-{frontend|backend}-{class}`. A template may use its own namespace classes and root classes, but not classes from another rendered area.

Package translations are package-scoped. A package may ship `languages/<locale>/*.yaml`; when it does, at least one catalogue for the configured fallback locale or its primary language must be present as the fallback source. Only active package language files are aggregated into the generated runtime `messages` catalogue during the package rebuild queue, so inactive packages cannot override or leak copy. Package-owned translation keys must stay namespaced below `pkg.<package-slug>.*`.

Database-backed schema Twig is not visible to Tailwind file scanning by itself. Schema rendering needs a later aggregation layer that extracts or stores CSS class usage from active schema Twig and exposes it to the Tailwind rebuild before production builds depend on schema-authored classes.

Template paths use logical Twig namespaces. Packages may ship frontend views under `templates/frontend/**` and reference templates as `@frontend/...`. Packages may ship backend views under `templates/backend/**` and reference templates as `@backend/...`. Frontend and backend theme scopes are the only scopes searched before native templates, so modules and providers can add package-specific views but do not replace matching core UI templates. Shared fallbacks use `@root/...`; packages may reference root templates, but only packages with `system-template` scope may override root-level shared files such as `base.html.twig` or `macros/core/**`.

Optional provider markup should use stable native slots. Core templates render stable stubs such as `@frontend/partials/forms/fields/captcha.html.twig` or `@backend/editor/fields/richtext.html.twig`; those stubs include templates through the shared `@provider` namespace. Provider package paths are searched before native provider fallbacks, while frontend and backend themes do not participate in `@provider` lookup. Missing captcha providers must not be treated as validation success in Twig; the matching backend provider service remains responsible for no-op/resolved behavior when no provider is active.

Package-owned macros are additive and use a directory namespace:

```text
packages/<package-slug>/templates/macros/<package-slug>/*.html.twig
```

Packages must not write macro files directly under `templates/macros/`, under another package slug, or under `templates/macros/core/**` unless they declare `system-template`.

## Event Hooks

Packages may subscribe only to public hooks surfaced by `App\Core\Event\PublicEventHookRegistry`. The registry aggregates domain-owned hook providers and is the source of truth for stable package extension contracts. Other Symfony events can still exist inside the application, but they are internal unless listed there.

Core dispatch points use `App\Core\Event\PublicEventDispatcher`, which converts listener failures into structured operation issues and emits the internal `App\Core\Event\PublicHookFailedEvent`. Package subscribers should still avoid throwing where a recoverable result is possible. Unrecoverable package listener failures may cause the package lifecycle to mark the package `faulty` once package ownership can be resolved safely.

Current public hooks:

- `App\View\ViewContextEvent`: extend the universal Twig context.
- `App\Content\Event\ContentRenderContextEvent`: extend Twig context for one public content render.
- `App\Content\Event\ContentRenderedEvent`: adjust generated HTML for one public content render.
- `App\Navigation\Event\NavigationBuilderEvent`: extend navigation items before tree hierarchy and active state are resolved. URL targets may use relative paths or safe `http`/`https` links; unsafe schemes are normalized away by the core builder before rendering.
- `App\View\Injection\Event\StaticViewInjectionRegistryEvent`: add static route/menu view injections for the `public`, `admin`, or `editor` surface.
- `App\View\Injection\Event\DynamicViewInjectionRegistryEvent`: add content-aware dynamic slot or variant-route injections for physical Twig templates.
- `App\View\Event\ResponseHeadersEvent`: adjust HTTP response headers before sending.
- `App\View\Event\OutputGeneratedEvent`: adjust generated HTML output after rendering.
- `App\Core\Package\Event\PackageAssetSyncStartedEvent`: observe the active package set before asset sync.
- `App\Core\Package\Event\PackageAssetRegistryBuildEvent`: add CSS, JavaScript, or Tailwind registry contributions before registries are written.
- `App\Core\Package\Event\PackageAssetSyncCompletedEvent`: observe package asset sync metrics after registry generation.

Subscribers should use Symfony-native event subscription and the event class name:

```php
use App\View\ViewContextEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class PackageSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ViewContextEvent::class => 'onViewContext'];
    }

    public function onViewContext(ViewContextEvent $event): void
    {
        $event->set('package_demo', ['enabled' => true]);
    }
}
```

Developers can inspect the currently surfaced hooks through `event_hooks()` in Twig. This helper is intended for debug comments and future admin diagnostics, not for package control flow.

Output hooks should stay narrow. Prefer Twig context hooks and templates for normal rendering work; use `OutputGeneratedEvent` only when the final HTML string is the correct boundary.

Do not expect package hooks for template path collection or runtime asset collection. Template namespaces are resolved through the package/theme lifecycle, and active package assets are mirrored and compiled through AssetSync and `assets:rebuild`.

Packages must not define new core permission rules dynamically. A package can require existing ACL levels, groups, roles, or manifest capabilities for its routes and UI, but the security model itself stays core-owned.

Backend page contributions should use static view injections on the `admin` or `editor` surface. Static injections provide a path slug, optional parent slug, label key, physical Twig template, sort order, access level/groups, optional link attributes, and menu visibility. Public package routes that should not permanently reserve one hard-coded path may use a configurable static route set: the route tree declares a default parent slug, while a package setting can move the whole tree to another free path. Core backend views, public content entities, and system views keep priority over injected package paths.

Dynamic public content contributions should use dynamic view injections with declarative filters. Slot injections render before or after the core content field block; route injections may claim missing content variant suffixes, but they must not replace an existing content entity or an existing content variant.

Schema `custom_twig` belongs to the inner content fieldset only. The native public content template keeps the page header, package injection slots, and outer content chrome stable, then delegates the variable fieldset to schema Twig with a generic fallback when custom Twig is empty or invalid. Custom schema Twig receives `content_view`, `content`, `revision`, `schema`, `schema_version`, `fields`, `language`, and `variant`.

Markdown rendering is profile-aware through the `render_markdown` Twig filter. The default profile is `allrounder`, which enables rich Markdown features, heading anchors, task lists, tables, footnotes, description lists, highlights, safe attributes, and external-link handling while escaping raw HTML and omitting embeds. Package README rendering uses `readme`, which maps to GitHub-Flavored Markdown for developer-authored package documentation. Trusted schema or admin-controlled design fields may explicitly call `render_markdown('design')`; that profile allows raw HTML, controlled attributes, rich Markdown, and YouTube embeds through the native no-cookie embed adapter. Public untrusted inputs such as future comments should call `render_markdown('basic')`, which keeps the CommonMark baseline plus autolinks while escaping HTML and excluding richer layout controls.

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

Provider templates should follow the slot convention owned by the resolver. Current native slot examples are:

```text
packages/<package-slug>/templates/provider/captcha/field.html.twig
packages/<package-slug>/templates/provider/editor/richtext.html.twig
```

Native provider fallbacks live in the same structure below `templates/provider/**`. If no matching provider package is active, Twig resolves the native fallback through the same `@provider/...` include.

The native editor provider uses CodeMirror as its base implementation. The shared `@provider/editor/codemirror.html.twig` template accepts `name`, `value`, `language`, `line_wrapping`, `read_only`, `tab_size`, and `attributes`. Native aliases such as `@provider/editor/markdown.html.twig`, `@provider/editor/json.html.twig`, `@provider/editor/php.html.twig`, and `@provider/editor/html.html.twig` set practical language defaults while keeping the same context contract for future editor-provider packages. The native `@provider/editor/richtext.html.twig` fallback intentionally delegates to Markdown editing; a real WYSIWYG provider such as TinyMCE may replace only that template while CodeMirror remains active for code-oriented aliases.

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
php bin/console assets:rebuild
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
