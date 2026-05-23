# First-party modules and admin add-ons (Feature Draft)

> **Status**: Future  
> **Updated**: 2026-05-20   
> **Owner**: Core  
> **Purpose:** Future draft for optional first-party modules that extend admin UI, editor integrations, import/export workflows, navigation helpers, media presentation, logging views, and statistics dashboards.  

## Overview
- Defines first-party module candidates that may ship with or near the core product without becoming hard-coded core behavior.
- Covers LogViewer/statistics, optional editor providers, import/export UI modules, breadcrumbs, gallery/lightbox features, and similar add-ons.
- Depends on plugin modules, admin interface, operational workflows, logging/statistics, editor contracts, import/export contracts, media library, and theme extension points.
- Keeps the core lean while making common product features available as maintained first-party modules.

## Outline
The module system should not exist only for third parties. Some useful CMS features are good candidates for first-party modules: they are valuable, but they should remain optional, replaceable, and easier to disable than core infrastructure.

The first example is a LogViewer and statistics module. Logging and statistics collection can belong to core services, but viewing logs, filtering events, reading audit trails, showing suspicious activity, and presenting statistics dashboards can be provided by a module that plugs into the admin interface.

Editor integrations are another natural module family. CodeMirror can remain the first core editor integration, while a TinyMCE module may later replace or complement it if the editor provider contract can define the needed feature set cleanly. This keeps the door open for other editor providers without baking one UI editor permanently into core.

Import/export workflows also have a split boundary. Core should define operation formats, validators, parsers, serializers, dry-run behavior, and diff contracts. Optional first-party modules can provide richer admin UI surfaces such as copy/paste import panels with diff view, import presets, export preset management, saved scopes, and LLM collaboration packages.

Other candidates include breadcrumbs, lightbox/gallery presentation helpers, specialized media widgets, dashboard widgets, and public-theme helper modules. These should be added only when their contracts are clear enough that they do not become hidden core dependencies.

## Technical Specifications
- Treat first-party modules as normal modules under the same manifest, discovery, inactive-by-default, enablement, asset rebuild, permissions, and uninstall rules as third-party modules.
- Keep module candidates optional unless a later release explicitly promotes one into the default installation profile.
- Require modules to declare admin navigation, dashboard widgets, routes, permissions, assets, templates, translations, and operational actions through documented extension points.
- Require modules with frontend/admin assets to trigger the same Tailwind and AssetMapper lifecycle rebuilds as any other module.
- Keep module UI surfaces permission-aware and translated.
- Keep modules uninstallable or disableable without breaking core workflows.

## Candidate Modules

### LogViewer and statistics
- Provides admin UI for logs, audit events, security events, suspicious activity, GeoIP/statistics summaries, and operational action logs where permissions allow.
- Depends on the logging/statistics core services and admin UI.
- Should support filtering by time range, level/category, actor, target, route/workflow, and event type where available.
- Must redact protected values and follow log retention rules.
- May show aggregated statistics dashboards without turning analytics into surveillance.

### TinyMCE editor provider
- Optional editor provider that can replace or complement CodeMirror if the editor-provider contract supports the required features.
- Must define required capabilities such as Markdown/rich-text behavior, resolver-token insertion, autocomplete, validation feedback, diff integration, and asset lifecycle.
- Should remain optional until CodeMirror limitations require a richer editor provider.

### Importer UI
- Provides admin copy/paste import workflow, dry-run trigger, validation summary, and diff/review screen.
- Uses core import parsers, operation validation, diff services, permissions, and operational action logs.
- Should not own the canonical import format.

### Exporter presets
- Provides saved export scopes, LLM collaboration presets, context depth/limit presets, and repeatable export jobs.
- Uses core export formatters and resolver/context-gathering services.
- Keeps preset storage and UI separate from the core export contracts.

### Breadcrumbs
- Provides theme/admin helper services for breadcrumb trails based on content hierarchy, navigation state, resolver context, or module routes.
- Must respect visibility, ACL, language, variant, and route boundaries.

### Lightbox gallery
- Provides media presentation helpers for public themes, such as gallery rendering and lightbox behavior.
- Depends on the media library and theme extension points.
- Should keep assets namespaced and should not make public themes depend on the module unless enabled.

## Testing & Validation
- Test each first-party module candidate under normal module lifecycle rules: discovered inactive, enabled explicitly, assets rebuilt, disabled safely, and removed cleanly.
- Test permission checks for admin UI modules.
- Test LogViewer redaction and retention behavior.
- Test editor provider capability negotiation before allowing a provider to replace the default.
- Test importer/exporter UI modules consume core contracts instead of inventing private formats.
- Test public presentation helpers respect ACL, visibility, language, variant, and asset namespacing.

## Implementation Notes
- **Decision recorded:** Log viewing and statistics display should be planned as an optional first-party module rather than only as hidden core logging behavior.
- **Decision recorded:** TinyMCE can remain a candidate editor-provider module if CodeMirror cannot cover the desired authoring feature set.
- **Decision recorded:** Importer UI and exporter preset management can be modular first-party admin add-ons built on core import/export contracts.
- **Decision recorded:** Breadcrumbs and lightbox/gallery helpers are good examples of optional first-party presentation modules.
- **Deferred:** Decide which first-party modules ship with the first public release, which are bundled but disabled by default, and which remain future add-ons.
