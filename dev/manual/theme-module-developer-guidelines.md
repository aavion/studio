# Extension developer guidelines (Developer Guide)

> **Status**: Draft  
> **Updated**: 2026-06-21  
> **Owner**: Core  
> **Purpose:** Draft guidance for developing extensions, scoped themes, modules, providers, admin UI extensions, and first-party add-ons while the extension system is still being designed.  

## Overview

This guide captures the current direction for extension development. It is intentionally marked as a draft: contracts, folder names, lifecycle hooks, and UI constraints may change while the CMS core is implemented.

Use this guide as a working reference when building the native system extensions, admin UI, public themes, and first-party extensions. Prefer the feature drafts when they contain more specific decisions.

## Extension principles

- Use Symfony-native integration points first: services, tagged services, Twig, routes, forms, validators, voters, EventDispatcher, Messenger, core-controlled database contributions, AssetMapper, Tailwind, and translations.
- Keep extensions inactive after discovery until an administrator explicitly activates them.
- Validate manifests before loading classes, routes, templates, database contributions, permissions, assets, or providers.
- Keep extension points explicit:
  - **Observe:** react without changing the result.
  - **Extend:** add contributions through documented hooks or tagged services.
  - **Replace:** select one implementation through a contract, resolver, decoration, or configuration.
- Prefer resolver/provider contracts for one-active-provider behavior, such as captcha or editor providers.
- Keep extension assets namespaced.
- Trigger Tailwind build and AssetMapper compilation after lifecycle changes with frontend contributions.
- Keep extension secrets out of manifests, logs, screenshots, fixtures, and committed configuration.

## Extension scopes

Extensions live under `extensions/<extension-slug>/` and use `EXTENSION_*` manifest keys. `EXTENSION_SCOPE` is a DotEnv-style list such as `[frontend-theme, module]`, or a single value such as `module`. Capability scopes such as `api`, `database`, and `content-schema` gate matching extension contributions.

Expected extension shape:

```text
extensions/<extension-slug>/
  .manifest
  extension.php
  src/
  templates/
  assets/
  config/
  languages/
```

Required manifest keys:

```text
EXTENSION_AUTHOR=Aavion
EXTENSION_SLUG=example-extension
EXTENSION_NAME=Example Extension
EXTENSION_VERSION=1.0.0
EXTENSION_SCOPE=[frontend-theme, module]
EXTENSION_DEPENDENCIES=[]
```

`EXTENSION_SLUG` must be an owner slug: it starts with a lowercase letter, uses only lowercase letters, digits, and single hyphen-separated segments, stays at 60 characters or less, and matches the extension folder name exactly.

Optional source metadata stays split: `EXTENSION_SOURCE` points to the repository or release source root, and `EXTENSION_CHANNEL` identifies the branch or channel. The admin UI may turn those two values into a branch-specific link, but update tooling must still be able to reconstruct clone/fetch targets from the raw manifest values.

Additional `EXTENSION_*` manifest keys become typed immutable metadata and can be read by extension-owned code through `ExtensionSettings::get('{extension-slug}', 'manifest.{key_without_extension_prefix}')`. For example, `EXTENSION_SOMEKEY=hallo welt` in `icon-captcha` is exposed as `ExtensionSettings::get('icon-captcha', 'manifest.somekey')`; a later `ExtensionSettings::set()` for the same key stores an override in the DB and leaves the manifest unchanged.

Current constraints:

- Allowed scopes start as `frontend-theme`, `backend-theme`, `system-template`, `module`, `captcha-provider`, `editor-provider`, `api`, `database`, and `content-schema`.
- An extension is always activated or deactivated as one unit. Scopes describe capabilities, not separately switchable sub-extensions.
- Only one `frontend-theme`, one `backend-theme`, one `system-template`, and one provider extension of each provider type may be active at the same time.
- Multiple `module` extensions may be active at the same time.
- Activating a new single-active scope deactivates the previously active extension for that scope. If that extension also had module behavior, the module behavior is deactivated with it.
- Disabled extensions must not contribute services, routes, templates, assets, database tables, permissions, providers, subscribers, or handlers.
- Extensions may contribute routes, services, templates, assets, field types, editor actions, API resources, permissions, database tables, event subscribers, message handlers, or replaceable providers only through documented extension points.
- Extension-owned domain data should use the `database` contribution contract. Free-form Doctrine migration classes and arbitrary SQL are not part of the extension contract; core generates physical table names from `{database-prefix}{extension-slug_}{local-table}` and only purges tables under that prefix. Extension columns may use only portable column options such as `length`, `notnull`, and scalar `default`, not raw DDL fragments. Extension tables may use primary keys, regular indexes, unique indexes, and foreign keys to other tables owned by the same extension. Runtime PHP can use the `extension_db_fetch()`, `extension_db_insert()`, `extension_db_update()`, and `extension_db_delete()` facades for bounded CRUD access to those extension-owned tables. Updates to existing contributed tables are additive only; schema rewrites should use a new table and extension-owned data migration logic. Core-owned users, ACL groups, content items, extensions, and roles should be referenced through `extension_lookup()` or `extension_entity()`, not through extension-created foreign keys to core tables.
- Extensions with frontend or admin assets must participate in the asset rebuild workflow.
- Extensions need uninstall/remove behavior, including explicit confirmation before deleting extension-owned data.
- Failed activation or deactivation should roll back to the previous state where practical.
- Installable extensions are checked against core-owned extension policies before activation or ZIP apply. The file policy blocks clearly unsafe payload paths such as `.env*`, `bin/`, root `node_modules/`, `public/`, and `var/`. VCS/editor metadata, `docs/`, `tests/`, `.github/`, `.idea/`, and `.vscode/` are ignored by validation; ZIP apply also skips ignored development payloads such as VCS metadata and tests. PHP files are allowed only as root `extension.php` or below `src/`. Public assets belong under `assets/`; non-synced extension-private assets belong under `private-assets/`. Asset file types are not whitelist-limited, but executable/server-side files and bare HTML are blocked inside asset roots. Templates belong under `templates/`, and extension translations belong under `languages/` with `languages/en/` required whenever translations are present. Extension-local `vendor/` may be shipped when `composer.json` and `composer.lock` are present; `composer.json` is validated with Composer when available, while vendor contents are not deeply linted. Studio does not automatically include `vendor/autoload.php`; extension PHP can call `require_vendor('vendor/package')` to register the requested package's PSR-4 prefixes from extension-owned Composer metadata without executing Composer `files` or scripts, overlapping core/vendor Composer prefixes are rejected, and extension-local prefixes resolve longest-first. Extension-local `assets/node_modules/` may be shipped when `assets/package.json` and a recognized Node lock file are present; node dependency contents are not deeply linted. Extension-local `config/` remains allowed for extension-owned data until a stricter extension configuration contract is decided. The PHP capability policy blocks direct filesystem, process, network, request-context, and environment access from extension PHP; extensions should request work through documented extension points instead.

`extension.php` is optional. It must never be included during discovery and should only be loaded after an extension is valid and active. Extensions are trusted code; only administrators may install them. An extension should use an extension-owned root namespace derived from or declared for the extension slug. Use `require_vendor('vendor/package')` when runtime PHP needs one shipped Composer package; do not expect Studio to load the extension's vendor autoloader. Use contribution-based `ExtensionOperationDefinition` entries plus an `ExtensionActionQueueProviderInterface` when extension code needs an operator- or scheduler-started ActionLog workflow; the shared `extension.operation` live operation starts only registered owner-prefixed targets, and scheduler tasks can reuse those same action queues. Use `extension_cache_set()`, `extension_cache_get()`, and `extension_cache_delete()` for TTL-bound extension-owned runtime artifacts such as captcha challenge state. Use `extension_storage_put()`, `extension_storage_get()`, `extension_storage_delete()`, `extension_storage_exists()`, and `extension_storage_list()` for durable extension-owned files that should live outside the extension package itself, and `extension_upload_store()` to import ordinary Symfony `UploadedFile` request uploads into that caller-owned storage boundary. Upload imports reject traversal, oversized files, and high-risk executable, server-side, script, SVG, and archive payloads by extension and MIME; ZIP/archive handling remains a dedicated follow-up. Use `extension_request()` when runtime code needs the current request method/path/route/locale plus bounded query/body/header/cookie/file metadata; sensitive values, cookie values, upload contents, and upload temporary paths are redacted. Use `extension_content_query()` and `extension_content_get()` for published public content references visible to the current actor; these helpers return routing/schema metadata and do not expose raw field values or revisions. Use `extension_can()` for current-actor role, ACL group, and content view/edit/manage checks; the helper returns only a boolean and does not accept caller-supplied actor identity. Use `extension_csrf_token()` and `extension_csrf_valid()` for extension-scoped Symfony CSRF tokens. Use `extension_cookie_get()`, `extension_cookie_set()`, and `extension_cookie_delete()` only for cookies registered by the calling extension through `CookieConsentDefinition`; optional cookies require consent and all writes preserve the registered cookie identity. Use `extension_trans()` for caller-owned `ext.<extension-slug>.*` translation keys. Use `extension_file_get()` for read-only access to files below the calling extension directory, `extension_asset()` for public or private asset content reads, and `extension_asset_url()` only for mirrored public asset URLs. Use `extension_settings_get()` for extension-owned settings, `extension_live_url()` and `extension_api_url()` for caller-owned endpoints, `extension_http_request()` for bounded outbound HTTP(S), `extension_log()` for message-layer diagnostics, and `extension_alert()` for UI alerts to the current request/session, specific users, ACL groups, minimum-access role topics, or validated system alert topics. `extension_log()` and `extension_alert()` accept caller-owned extension translation keys; literals, core keys, and foreign extension keys are wrapped in the system fallback message. `extension_mail()` exists only as a development-stub boundary until configurable mail workflow contributions and real mail delivery are finalized.

When `EXTENSION_NAMESPACE` is declared, PHP files below `src/` must use that namespace or one of its child namespaces. The active runtime loader includes only `extension.php`; that file may return simple contribution DTOs/providers or a callable that returns them, but it must not directly include files, read or write files, spawn processes, open network sockets, read raw environment/request globals, or bypass extension points. For multiple contributions, prefer `App\Core\Extension\ExtensionContributions::create()` so the extension entry point remains readable and each contribution type is named. Supported direct contributions currently include static view injections, configurable static route sets, dynamic view injections, extension setting definitions, scheduler task definitions, API endpoints and handlers, declarative database tables, and content schema presets. API contributions require `api` scope, database contributions require `database` scope, and content schema contributions require `content-schema` scope. Runtime view templates must stay in the extension-owned namespace for their surface: public views use `@frontend/{extension-slug}/...`, and admin/editor views use `@backend/{extension-slug}/...`. Loader failures are caught by the lifecycle layer, recorded as structured diagnostics, and mark the extension `faulty` so a broken active extension does not keep breaking requests. Contribution iterables are staged before registry mutation, so one unsupported item rejects the full extension contribution for the current request.

Extension assets must be self-contained. Extensions should vendor their external dependencies inside their own extension directory instead of requiring the project importmap to manage third-party dependency lifecycles across extensions. Active extension CSS and JavaScript are aggregated through the generated extension asset registries; extensions should not expect templates to add arbitrary direct `<link>` or `<script>` tags for extension-level assets. Static assets such as images, fonts, videos, and SVGs should be referenced from extension CSS, JavaScript, or templates after the lifecycle mirrors them into the AssetMapper-visible extension path. Assets that must not be mirrored, such as server-side challenge images or provider-private indexes, belong under `private-assets/`.

Area-specific extension assets follow the same boundary as template namespaces. An extension with `frontend-theme` should put frontend-only entrypoints under `assets/frontend/**`; an extension with `backend-theme` should put backend-only entrypoints under `assets/backend/**`. Root-level extension assets and other extension asset subdirectories are shared/global and enter the extension registry only when the extension also declares a global scope such as `module`, `captcha-provider`, `editor-provider`, or `system-template`.

Extension asset registries control deterministic rebuild order, but Tailwind currently emits one application stylesheet. CSS that belongs to one rendered area should therefore stay scoped to that area's root class, such as `.system-frontend` or `.system-backend`, unless the extension intentionally contributes global module/provider styling. Root/shared templates use `{system|extension-slug}-{class}` selectors, provider templates use `{system|extension-slug}-{provider-scope}-{class}`, and frontend/backend templates use `{system|extension-slug}-{frontend|backend}-{class}`. A template may use owner-wide classes and classes from its own declared/rendered scope, but not classes from another rendered area.

Extension translations are extension-scoped. An extension may ship `languages/<locale>/*.yaml`; when it does, at least one English catalogue under `languages/en/` must be present as the stable fallback source. Only active extension language files are aggregated into the generated runtime `messages` catalogue during the extension rebuild queue, so inactive extensions cannot override or leak copy. Extension-owned translation keys must stay namespaced below `ext.<extension-slug>.*`.

Database-backed schema Twig is not visible to Tailwind file scanning by itself. Schema rendering needs a later aggregation layer that extracts or stores CSS class usage from active schema Twig and exposes it to the Tailwind rebuild before production builds depend on schema-authored classes.

Template paths use logical Twig namespaces. Extensions may ship frontend views under `templates/frontend/**` and reference templates as `@frontend/...`. Extensions may ship backend views under `templates/backend/**` and reference templates as `@backend/...`. Frontend and backend theme scopes are the only scopes searched before native templates, so modules and providers can add extension-specific views but do not replace matching core UI templates. Shared fallbacks use `@root/...`; extensions may reference root templates, but only extensions with `system-template` scope may override root-level shared files such as `base.html.twig` or `macros/core/**`.

Optional provider markup should use stable native slots. Core templates render stable stubs such as `@frontend/partials/forms/fields/captcha.html.twig` or `@backend/editor/fields/richtext.html.twig`; those stubs include templates through the shared `@provider` namespace. Provider extension paths are searched before native provider fallbacks, while frontend and backend themes do not participate in `@provider` lookup. Missing captcha providers must not be treated as validation success in Twig; the matching backend provider service remains responsible for no-op/resolved behavior when no provider is active.

Extension-owned macros are additive and use a directory namespace:

```text
extensions/<extension-slug>/templates/macros/<extension-slug>/*.html.twig
```

Extensions must not write macro files directly under `templates/macros/`, under another extension slug, or under `templates/macros/core/**` unless they declare `system-template`.

## Event Hooks

Extensions may subscribe only to public hooks surfaced by `App\Core\Event\PublicEventHookRegistry`. The registry aggregates domain-owned hook providers and is the source of truth for stable extension contracts. Other Symfony events can still exist inside the application, but they are internal unless listed there.

Core dispatch points use `App\Core\Event\PublicEventDispatcher`, which converts listener failures into structured operation issues and emits the internal `App\Core\Event\PublicHookFailedEvent`. Native Symfony listener failures fail the public hook dispatch, but extension listener failures are isolated so later extension listeners still run. Extension subscribers should still avoid throwing where a recoverable result is possible. Unrecoverable extension listener failures may cause the extension lifecycle to mark the extension `faulty` once extension ownership can be resolved safely.

Current public hooks:

- `App\View\ViewContextEvent`: extend the universal Twig context.
- `App\Content\Event\ContentRenderContextEvent`: extend Twig context for one public content render.
- `App\Content\Event\ContentRenderedEvent`: adjust generated HTML for one public content render.
- `App\Navigation\Event\NavigationBuilderEvent`: extend navigation items before tree hierarchy and active state are resolved. URL targets may use relative paths or safe `http`/`https` links; unsafe schemes are normalized away by the core builder before rendering.
- `App\View\Injection\Event\StaticViewInjectionRegistryEvent`: add static route/menu view injections for the `public`, `admin`, or `editor` surface.
- `App\View\Injection\Event\DynamicViewInjectionRegistryEvent`: add content-aware dynamic slot or variant-route injections for physical Twig templates.
- `App\View\Event\ResponseHeadersEvent`: adjust HTTP response headers before sending.
- `App\View\Event\OutputGeneratedEvent`: adjust generated HTML output after rendering.
- `App\Core\Extension\Event\ExtensionAssetSyncStartedEvent`: observe the active extension set before asset sync.
- `App\Core\Extension\Event\ExtensionAssetRegistryBuildEvent`: add CSS, JavaScript, or Tailwind registry contributions before registries are written.
- `App\Core\Extension\Event\ExtensionAssetSyncCompletedEvent`: observe extension asset sync metrics after registry generation and mirror commit; listener failures are reported as post-commit warnings and do not roll back the already-written files.

Subscribers should use Symfony-native event subscription and the event class name:

```php
use App\View\ViewContextEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ExtensionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ViewContextEvent::class => 'onViewContext'];
    }

    public function onViewContext(ViewContextEvent $event): void
    {
        $event->set('extension_demo', ['enabled' => true]);
    }
}
```

Developers can inspect the currently surfaced hooks through `event_hooks()` in Twig. This helper is intended for debug comments and future admin diagnostics, not for extension control flow.

Output hooks should stay narrow. Prefer Twig context hooks and templates for normal rendering work; use `OutputGeneratedEvent` only when the final HTML string is the correct boundary.

Do not expect extension hooks for template path collection or runtime asset collection. Template namespaces are resolved through the extension/theme lifecycle, and active extension assets are mirrored and compiled through AssetSync and `assets:rebuild`.

Extensions must not define new core permission rules dynamically. An extension can require existing ACL levels, groups, roles, or manifest capabilities for its routes and UI, but the security model itself stays core-owned.

Backend page contributions should use static view injections on the `admin` or `editor` surface. Static injections provide a path slug, optional parent slug, label key, physical Twig template, sort order, access level/groups, optional link attributes, and menu visibility. Public extension routes that should not permanently reserve one hard-coded path may use a configurable static route set: the route tree declares a default parent slug, while an extension setting can move the whole tree to another free path. Core backend views, public content entities, and system views keep priority over injected extension paths.

Dynamic public content contributions should use dynamic view injections with declarative filters. Slot injections render before or after the core content field block; route injections may claim missing content variant suffixes, but they must not replace an existing content entity or an existing content variant.

Schema `custom_twig` belongs to the inner content fieldset only. The native public content template keeps the page header, extension injection slots, and outer content chrome stable, then delegates the variable fieldset to schema Twig with a generic fallback when custom Twig is empty or invalid. Custom schema Twig receives `content_view`, `content`, `revision`, `schema`, `schema_version`, `fields`, `language`, and `variant`.

Markdown rendering is profile-aware through the `render_markdown` Twig filter. The default profile is `allrounder`, which enables rich Markdown features, heading anchors, task lists, tables, footnotes, description lists, highlights, safe attributes, and external-link handling while escaping raw HTML and omitting embeds. Extension README rendering uses `readme`, which maps to GitHub-Flavored Markdown for developer-authored extension documentation. Trusted schema or admin-controlled design fields may explicitly call `render_markdown('design')`; that profile allows raw HTML, controlled attributes, rich Markdown, and YouTube embeds through the native no-cookie embed adapter. Public untrusted inputs such as future comments should call `render_markdown('basic')`, which keeps the CommonMark baseline plus autolinks while escaping HTML and excluding richer layout controls.

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
- Use the shared action-log pattern for setup, imports, backups, updates, asset rebuilds, extension lifecycle changes, and other long-running operations.
- Keep navigation and dashboard contributions permission-aware.
- Meet baseline accessibility expectations: semantic landmarks, keyboard navigation, visible focus, color contrast, labels, error association, and reduced-motion safety.

## Provider Extensions

Provider extensions should implement a documented contract and be selectable through core-owned configuration.

Examples:

- Captcha providers such as IconCaptcha.
- Editor providers such as a future TinyMCE module.
- Search, storage, export, or media adapters.

Provider extensions should define required capabilities before they are allowed to replace a default. For editor providers, this may include Markdown/rich-text behavior, resolver-token insertion, autocomplete, validation feedback, diff integration, and asset lifecycle support.

Provider templates should follow the slot convention owned by the resolver. Current native slot examples are:

```text
extensions/<extension-slug>/templates/provider/captcha/field.html.twig
extensions/<extension-slug>/templates/provider/editor/richtext.html.twig
```

Native provider fallbacks live in the same structure below `templates/provider/**`. If no matching provider extension is active, Twig resolves the native fallback through the same `@provider/...` include.

The native editor provider uses CodeMirror as its base implementation. The shared `@provider/editor/codemirror.html.twig` template accepts `name`, `value`, `language`, `line_wrapping`, `read_only`, `tab_size`, and `attributes`. Native aliases such as `@provider/editor/markdown.html.twig`, `@provider/editor/json.html.twig`, `@provider/editor/php.html.twig`, and `@provider/editor/html.html.twig` set practical language defaults while keeping the same context contract for future editor-provider extensions. The native `@provider/editor/richtext.html.twig` fallback intentionally delegates to Markdown editing; a real WYSIWYG provider such as TinyMCE may replace only that template while CodeMirror remains active for code-oriented aliases.

## Testing and validation

Extension work should include tests for:

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
- [Extension modules and providers draft](../draft/0.2.x-PluginModules.md)
- [System theme and design system draft](../draft/0.1.x-SystemThemeDesignSystem.md)
- [Operational admin workflows draft](../draft/0.4.x-OperationalAdminWorkflows.md)
