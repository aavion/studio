# Admin UI snippets

> **Status**: Draft  
> **Updated**: 2026-05-23  
> **Owner**: Core  
> **Purpose:** Collect early admin interface and operational UI notes before the system theme and admin screens are implemented.  

## Overview

The admin UI should be quiet, dense, predictable, and work-focused. Avoid marketing-style layouts, oversized heroes, nested cards, and decorative page sections.

## Current UI principles

- Prefer tables, forms, filters, tabs, dialogs, status indicators, and action logs.
- Keep cards for repeated items, modals, or genuinely framed tools.
- Do not nest cards inside cards.
- Use icons for common tool buttons and text buttons for clear commands.
- Use translated strings for user-facing labels, help text, validation, empty states, and flash messages.
- Preserve submitted input on validation failure.
- Put destructive workflows behind confirmation and review screens.

## Operational screens

Future operational screens should reuse common patterns:

| Screen | Likely primary content |
|--------|------------------------|
| Package detail | Manifest, inventory, features, lint results, compatibility, actions. |
| Import review | Diffs, risks, affected paths/entities, confirmation. |
| Action log | Timeline, status counts, issues, context payload. |
| Backup/restore | Snapshot metadata, checksums, retention, restore plan. |
| Module/theme lifecycle | Discovery state, activation status, dependencies, rollback notes. |

## Accessibility notes

Keep these as baseline checks:

- semantic landmarks;
- keyboard navigation;
- visible focus;
- labels and error association;
- contrast;
- reduced-motion safety;
- readable compact layouts on mobile and desktop.

## References

- [Theme and module developer guidelines](theme-module-developer-guidelines.md)
- [System theme and design system draft](../draft/0.1.x-SystemThemeDesignSystem.md)
- [Admin interface and setup UI draft](../draft/0.2.x-AdminInterfaceSetupUi.md)
- [Operational admin workflows draft](../draft/0.4.x-OperationalAdminWorkflows.md)
