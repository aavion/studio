# Future Branding Extensions

> **Status**: Draft  
> **Updated**: 2026-06-21  
> **Owner**: Core  
> **Purpose:** Future design note for single-active branding packages that override rendering and styling without becoming general modules.  

## Overview

Branding packages should let an operator apply site-wide visual identity changes with one activation: logos, typography, CSS tokens, small copy marks, and Twig/CSS/JavaScript overrides that affect rendered output. They are intended as a precise rendering/styling layer above active themes, not as general application modules.

This is deferred out of the current extension-runtime branch because it touches manifest policy, Twig namespace order, asset import priority, validator exceptions, lifecycle UI, and review-sensitive override rules.

## Scope Model

Add a future `branding` extension scope with these rules:

- `branding` is a theme-family identity scope, but it is exclusive.
- A branding package may declare only `EXTENSION_SCOPE=branding`.
- Branding packages are single-active and may be active together with frontend/backend/system themes.
- Branding packages must not load `extension.php`, PHP classes, runtime boot code, providers, event listeners, scheduler tasks, operations, API/live handlers, database tables, content schema presets, cookies, or settings.
- Branding packages may ship templates, translations if needed for rendered copy, and assets.
- Branding packages may override all Twig namespaces and root-level CSS tokens without the ordinary extension selector-prefix requirement.
- Branding templates/assets should be imported with higher priority than active themes so branding can intentionally override theme output.

## Dynamic Branding Alternative

A future Admin UI could store branding choices in the database instead of installing a package. That is attractive for simple values such as logo paths, colors, site labels, and selected fonts. However, Tailwind cannot derive arbitrary utilities from database state at runtime, and DB-backed Twig/CSS overrides would blur the boundary between content/configuration and executable rendering code.

For rich branding, package-based Twig/CSS/asset overrides remain the cleaner first design. DB-backed branding may still be useful later for a constrained token editor that writes known CSS variables, selected assets, and simple labels without arbitrary Twig/CSS execution.

## Technical Notes

Implementation should be a dedicated reviewable slice:

- Extend `ExtensionScope` with `branding` and enforce exclusivity at manifest parsing/discovery.
- Add validator policy for branding packages: no PHP runtime/contributions, no general capability scopes, no database/API/scheduler/operation behavior.
- Adjust Twig namespace/path ordering so branding overrides are applied above active themes.
- Adjust asset aggregation so branding CSS/JS is ordered after themes where override semantics require it, while keeping unsafe asset file types blocked.
- Decide whether branding translations are allowed and how they are namespaced.
- Surface branding as a separate Admin category from themes/modules/providers.
- Add tests for exclusivity, no PHP loading, no contribution execution, Twig priority, asset priority, and safe asset filtering.

## Testing & Validation

- Manifest parsing rejects `branding` combined with any other scope.
- Branding packages with `extension.php` or `src/` PHP are rejected or ignored by policy.
- Branding templates can override root/frontend/backend/provider namespaces according to the final resolver order.
- Branding assets can override theme tokens/classes in deterministic order.
- Active branding does not affect provider selection, scheduler, operations, API/live endpoints, database synchronization, or content schema synchronization.

## Deferred Decisions

- Exact Twig namespace precedence per surface.
- Whether branding packages can ship translations and how conflicts are resolved.
- Whether a constrained Admin token editor should coexist with package-based branding.
- Whether CSS prefix exceptions apply only to CSS custom properties/tokens or to all branding CSS selectors.
