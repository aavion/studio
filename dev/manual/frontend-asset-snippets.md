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

## Theme asset notes

Theme and module packages should keep assets namespaced. Lifecycle workflows should decide when assets are copied, installed, compiled, or removed.

## Illustration color note

SVG background images do not inherit CSS custom properties. Dynamic unDraw coloring should later use an allowlisted inline SVG renderer or Twig component if theme-aware coloring is required.

## Rebuild triggers

Potential rebuild triggers:

- theme activation;
- module enablement;
- module disablement;
- package import;
- update install;
- setup completion;
- production deploy.

## References

- [Theme and module developer guidelines](theme-module-developer-guidelines.md)
- [System theme and design system draft](../draft/0.1.x-SystemThemeDesignSystem.md)
- [Frontend delivery and caching draft](../draft/0.4.x-FrontendDeliveryCaching.md)
