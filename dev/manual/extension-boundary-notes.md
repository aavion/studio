# Extension boundary notes

> **Status**: Draft  
> **Updated**: 2026-05-23  
> **Owner**: Core  
> **Purpose:** Record which responsibilities belong to neutral Core primitives and which should remain in theme, module, installer, or lifecycle workflows.  

## Overview

Core should stay useful across app setup, themes, modules, imports, updates, and future admin workflows. Avoid adding theme- or module-specific policy to shared primitives before a concrete lifecycle needs it.

## Core responsibilities

Core can provide:

- manifest parsing and validation primitives;
- extension discovery sources;
- extension inventories and feature inspection;
- syntax lint providers;
- checksums;
- structured diffs;
- dry-run plans;
- action queues;
- filesystem and process actions;
- action logs;
- workflow result and issue payloads.

## Lifecycle responsibilities

Installer or extension-type lifecycle code should decide:

- extension activation and deactivation;
- dependency maps;
- version compatibility;
- declarative database contributions;
- route loading;
- service loading;
- provider replacement;
- permission registration;
- asset rebuild strategy;
- rollback behavior;
- uninstall and data deletion behavior.

## Provider boundaries

Provider-style modules should expose explicit capabilities before replacing a default implementation. Examples:

- captcha provider;
- editor provider;
- storage provider;
- search provider;
- export/import adapter.

Use one-active-provider configuration where only one implementation can be selected at a time. Use tagged contributions where multiple providers can add capabilities safely.

## Dependency map notes

Dependency maps are intentionally deferred. They should likely include:

- extension id;
- extension type;
- version constraints;
- required core version;
- required PHP extensions;
- required Composer packages;
- declarative extension-owned database tables;
- immutable content schema presets;
- required extensions;
- conflicting extensions;
- optional integrations.

Do not make `ExtensionValidator` enforce dependency maps until installer workflows can decide how to display, resolve, and roll back dependency decisions.

## References

- [Extension lifecycle snippets](extension-lifecycle-snippets.md)
- [Extension developer guidelines](theme-module-developer-guidelines.md)
- [Extension modules and providers draft](../draft/0.2.x-PluginModules.md)
- [Self-update and release workflow draft](../draft/0.5.x-SelfUpdateReleaseWorkflow.md)
