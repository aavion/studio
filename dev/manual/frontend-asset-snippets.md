# Frontend asset snippets

> **Status**: Draft  
> **Updated**: 2026-05-23  
> **Owner**: Core  
> **Purpose:** Record early notes for AssetMapper, ImportMap, Tailwind, theme assets, illustrations, and package asset rebuilds.  

## Overview

Frontend assets are currently Symfony-first: AssetMapper, ImportMap, Tailwind, Twig, and Stimulus. Avoid adding Node-specific assumptions unless a future workflow explicitly needs them.

## Build notes

Composer auto-scripts currently handle:

- public asset installation;
- ImportMap install;
- Tailwind build.

`bin/init` should avoid duplicating those commands and only run `asset-map:compile` in `prod`.

The global package-aware rebuild entry point should be `php bin/console studio:assets:rebuild`. Package lifecycle workflows and manual admin recovery actions should call this command through the operational ActionLog runner, not rebuild assets during normal page requests.

The command should publish a planned step count before execution. The planned order is:

1. mirror active package assets and rewrite generated package asset registries;
2. run `assets:install`;
3. run `importmap:install`;
4. run `tailwind:build`;
5. only in `prod`, remove `public/assets` and run `asset-map:compile`;
6. run `cache:clear` as the finalizer.

`cache:clear` intentionally runs last. The rebuild should run in a CLI worker or subprocess with persisted ActionLog entries, while the UI reads progress through streaming or `/api/live/operations/{operationId}/log?cursor=<number>`. If clearing the cache briefly interrupts polling, the UI can resume from the stored cursor. The command must not depend on the current HTTP request continuing after cache invalidation.

## Theme asset notes

Packages should keep assets namespaced. Active package assets are not loaded directly from `packages/` and inactive package assets must never become public. The package lifecycle mirrors only active package assets into `assets/packages/<package-slug>/`, then rewrites the stable generated registry files:

- `assets/styles/packages/extension.css`
- `assets/styles/packages/frontend-theme.css`
- `assets/styles/packages/backend-theme.css`
- `assets/js/packages/extension.js`
- `assets/js/packages/frontend-theme.js`
- `assets/js/packages/backend-theme.js`

`assets/styles/app.css` imports the CSS registries after native system styles. Tailwind therefore sees active package `@source` entries and `@import` entries before it writes the built aggregate CSS that AssetMapper serves instead of the source input. `assets/app.js` imports the JavaScript registries after native system JavaScript and before Alpine starts.

The deterministic order is:

1. native system CSS/JS;
2. active module and provider package CSS/JS;
3. active frontend theme package CSS/JS;
4. active backend theme package CSS/JS;
5. project-local or entity-local assets where a renderer explicitly adds them.

Packages may ship self-contained third-party CSS or JavaScript inside their own `assets/` directory. The lifecycle mirrors those files as package assets instead of injecting package-managed third-party dependencies into the global importmap.

Static assets such as images, fonts, videos, SVGs, and vendored dependency files are mirrored into the same package asset directory but are not written into the CSS or JavaScript registries by themselves. They become public only when active package CSS, JavaScript, or templates reference them from the mirrored path.

Package CSS should reference mirrored static assets with paths that remain valid after Tailwind aggregation. The lifecycle may normalize or rewrite `url()` references during the mirror step when needed. Raw references to files under `packages/<slug>/...` are not allowed in rendered CSS because source package directories are not AssetMapper public roots.

External dependencies are intentionally package-local. A package should vendor browser-side dependencies under its own `assets/` tree and reference them from its package CSS/JS. The project should not add or remove global importmap pins for package-owned third-party code, because two active packages may need different versions of the same dependency. Files under package `vendor`, `vendors`, or `node_modules` paths are mirrored without CSS/JS path rewriting so internally consistent dependency bundles keep working.

## Illustration color note

SVG background images do not inherit CSS custom properties. Dynamic unDraw coloring should later use an allowlisted inline SVG renderer or Twig component if theme-aware coloring is required.

## Rebuild triggers

Potential rebuild triggers:

- package activation;
- package deactivation;
- package import;
- manual admin rebuild;
- update install;
- setup completion;
- production deploy.

## References

- [Package developer guidelines](theme-module-developer-guidelines.md)
- [System theme and design system draft](../draft/0.1.x-SystemThemeDesignSystem.md)
- [Frontend delivery and caching draft](../draft/0.4.x-FrontendDeliveryCaching.md)
