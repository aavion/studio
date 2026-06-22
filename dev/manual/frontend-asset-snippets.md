# Frontend asset snippets

> **Status**: Draft  
> **Updated**: 2026-06-13
> **Owner**: Core  
> **Purpose:** Record early notes for AssetMapper, ImportMap, Tailwind, theme assets, illustrations, and extension asset rebuilds.  

## Overview

Frontend assets are currently Symfony-first: AssetMapper, ImportMap, Tailwind, Twig, and Stimulus. Avoid adding Node-specific assumptions unless a future workflow explicitly needs them.

## Build notes

Composer auto-scripts currently handle:

- public asset installation;
- ImportMap install;
- Tailwind build.

`bin/init` reruns the asset setup commands after Composer has restored dependencies so clean checkouts and recovered `vendor/` trees have deterministic local assets, then warms the Symfony cache so UX Translator can dump JavaScript translation assets. It only runs `asset-map:compile` in `prod`.

The global extension-aware rebuild entry point is `php bin/console assets:rebuild`. Extension lifecycle workflows and manual admin recovery actions should call this command through the operational ActionLog runner, not rebuild assets during normal page requests.

The command publishes a planned step count in dry-run mode and reports current step progress during execution. The order is:

1. mirror active extension assets and rewrite generated extension asset registries;
2. aggregate core and active extension translation sources into the runtime `messages` catalogues;
3. run `assets:install`;
4. run `importmap:install`;
5. run `ux:translator:warm-cache` so AssetMapper can resolve `var/translations/index.js`;
6. run `ux:icons:lock` as a non-blocking step so core and extension template icon references are imported locally when Iconify is reachable;
7. run `tailwind:build`;
8. only in `prod`, remove `public/assets` and run `asset-map:compile`;
9. run `cache:clear` as the finalizer.

Symfony UX icons render inline from local SVG files under `assets/icons`; they do not need to be copied to `public/assets`. The lock step is intentionally non-blocking because offline CI, restricted production networks, or temporary Iconify outages should not break an otherwise valid asset rebuild. Missing icons are still visible as warnings in the ActionLog and should be locked manually during development or before release when network access is available. Before `ux:icons:lock`, `ux:icons:warm-cache`, or `asset-map:compile` run in the console, the extension template path configurator registers active extension template paths on Twig so scans include extension-owned Twig files under `extensions/**/templates`.

`bin/lint` performs the non-mutating counterpart: it scans static `ux_icon('...')` and `<twig:ux:icon name="...">` references in Twig files and verifies that the corresponding local SVG exists under `assets/icons`, resolving configured aliases from `config/packages/ux_icons.yaml`. This check is local-only and suitable for CI; it does not attempt Iconify network access.

Locked SVG files under `assets/icons` are committed as small, reviewable UI dependency snapshots. Do not bulk-lock complete upstream icon sets by default; add icons through real template usage, configured aliases, or explicit import decisions.

`cache:clear` intentionally runs last. The rebuild should run in a CLI worker or subprocess with persisted ActionLog entries, while the UI reads progress through streaming or `/api/live/operations/{operationId}/log?cursor=<number>`. If clearing the cache briefly interrupts polling, the UI can resume from the stored cursor. The command must not depend on the current HTTP request continuing after cache invalidation.

Use `php bin/console extensions:assets:sync` when only the active extension mirror and generated registry files need to be refreshed without running the full Symfony asset lifecycle.

Extension asset sync and translation aggregation should preserve the previous generated state until the replacement is ready. Extension assets are mirrored into a temporary `assets/.extensions.tmp-*` directory before `assets/extensions` is swapped, generated CSS/JavaScript registries are replaced through temporary files, and runtime translation catalogues are aggregated into a temporary `translations/runtime/{APP_ENV}.tmp-*` directory before the environment runtime directory is replaced. `ux:translator:warm-cache` runs after translation aggregation so the JavaScript translation assets in `var/translations` exist before AssetMapper resolves `assets/translator.js`. Production rebuilds still remove `public/assets` before `asset-map:compile` because AssetMapper writes versioned files and repeated compiles would otherwise leave stale compiled assets behind.

## Theme asset notes

Extensions should keep assets namespaced. Active extension assets are not loaded directly from `extensions/` and inactive extension assets must never become public. The extension lifecycle mirrors only active extension assets into `assets/extensions/<extension-slug>/`, then rewrites ignored generated registry files:

- `assets/styles/extensions/extension.css`
- `assets/styles/extensions/frontend-theme.css`
- `assets/styles/extensions/backend-theme.css`
- `assets/js/extensions/extension.js`
- `assets/js/extensions/frontend-theme.js`
- `assets/js/extensions/backend-theme.js`

`assets/styles/app.css` imports the CSS registries after native system styles. Tailwind therefore sees active extension `@source` entries and `@import` entries before it writes the built aggregate CSS that AssetMapper serves instead of the source input. `assets/app.js` imports the JavaScript registries after native system JavaScript and before Alpine starts.

The extension asset mirror and generated registries are runtime build artifacts. Git tracks only the extension asset directories, their `.gitignore` files, and their README anchors. `bin/init`, the Composer install/update hook, and extension asset sync all ensure the six registry files exist before Tailwind or AssetMapper can require them, so clean checkouts work while local extension activation does not dirty the Git index.

Database-backed schema Twig is not part of Tailwind's normal filesystem scan. Before schema-authored CSS classes are supported in production, the schema renderer needs a build input layer that aggregates class usage from active custom schema Twig and exposes it to `tailwind:build`, for example through a generated safelist/source artifact written during `assets:rebuild`.

The deterministic order is:

1. native system CSS/JS;
2. active module and provider extension CSS/JS;
3. active frontend theme extension CSS/JS;
4. active backend theme extension CSS/JS;
5. project-local or entity-local assets where a renderer explicitly adds them.

Template and asset scopes should mirror each other. Frontend-specific extension assets belong under `assets/frontend/**` and are written to the frontend-theme bucket only when the extension has `frontend-theme`. Backend-specific extension assets belong under `assets/backend/**` and are written to the backend-theme bucket only when the extension has `backend-theme`. Extension assets outside those folders are shared/global and are written to the extension bucket only when the extension has an identity scope such as `module`, `captcha-provider`, or `editor-provider`, or the `system-template` capability scope. A frontend-theme-only extension must not inject shared CSS/JS into the extension bucket because that would affect backend rendering after Tailwind aggregates everything into one CSS build.

The generated buckets are imported into the native Tailwind build in a deterministic order, but they do not create a browser-level CSS sandbox. Extension CSS that should affect only one shell should use area root selectors, for example `.system-frontend` for public rendering and `.system-backend` for admin/editor/setup rendering. A later extension validator may enforce selector namespaces if practical testing shows that extension CSS leakage is a recurring risk.

Extensions may ship self-contained third-party CSS or JavaScript inside their own `assets/` directory. The lifecycle mirrors those files as extension assets instead of injecting extension-managed third-party dependencies into the global importmap.

Static assets such as images, fonts, videos, SVGs, and vendored dependency files are mirrored into the same extension asset directory but are not written into the CSS or JavaScript registries by themselves. They become public only when active extension CSS, JavaScript, or templates reference them from the mirrored path.

Extension CSS should reference mirrored static assets with paths that remain valid after Tailwind aggregation. The lifecycle may normalize or rewrite `url()` references during the mirror step when needed. Raw references to files under `extensions/<slug>/...` are not allowed in rendered CSS because source extension directories are not AssetMapper public roots.

External dependencies are intentionally extension-local. An extension should vendor browser-side dependencies under its own `assets/` tree and reference them from its extension CSS/JS. The project should not add or remove global importmap pins for extension-owned third-party code, because two active extensions may need different versions of the same dependency. Files under extension `vendor`, `vendors`, or `node_modules` paths are mirrored without CSS/JS path rewriting so internally consistent dependency bundles keep working.

## Illustration color note

SVG background images do not inherit CSS custom properties. Dynamic unDraw coloring should later use an allowlisted inline SVG renderer or Twig component if theme-aware coloring is required.

## Rebuild triggers

Potential rebuild triggers:

- extension activation;
- extension deactivation;
- extension import;
- manual admin rebuild;
- update install;
- setup completion;
- production deploy.

## References

- [Extension developer guidelines](theme-module-developer-guidelines.md)
- [System theme and design system draft](../draft/0.1.x-SystemThemeDesignSystem.md)
- [Frontend delivery and caching draft](../draft/0.4.x-FrontendDeliveryCaching.md)
