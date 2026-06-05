# Project Readiness Drift Audit 2026-06-05

> **Status**: Active  
> **Issue**: #57  
> **Branch**: `audit-project-readiness`  
> **Purpose**: Preserve context and working notes for the first broad architecture, modularity, naming, performance, security, Symfony-alignment, and documentation-drift audit.

## Brief

Run a complete project audit without treating feature-draft assumptions or previous implementation choices as binding. Review the current codebase by domain and challenge decisions around modularity, public API naming, custom abstractions, tests, data-model choices, statistics identity, visitor/request IDs, session hardening, platform support, and security boundaries.

## Ground Rules

- Modular code is preferred over monolithic classes. Large files should be split when the boundary improves responsibility, reuse, or context stability.
- Public callables, interfaces, hooks, events, commands, routes, and extension points must be easy to name, document, and understand.
- Symfony and maintained vendor capabilities should be preferred over custom abstractions unless the custom layer provides clear project value.
- Tests should protect behavior and platform/security guarantees without overfitting volatile templates or implementation details.
- Performance and security decisions must be challenged, including identifier strategy, visitor/request identity, sessions, audit/statistics storage, indexing, pagination, process spawning, and filesystem scans.
- Findings should include area, evidence, impact, recommendation, and priority.

## Initial Inventory

- Relevant project files across source, tests, assets, templates, docs, config, and drafts: `944`.
- PHP lines in `src/` and `tests/`: `77085`.
- Largest current audit candidates:
  - `tests/Controller/AdminUserControllerTest.php`: `2226` lines.
  - `tests/Controller/UserControllerTest.php`: `1663` lines.
  - `tests/Controller/BackendControllerTest.php`: `1386` lines.
  - `src/Core/Package/Install/PackageZipInstaller.php`: `1134` lines.
  - `src/Core/Operation/Live/LiveOperationRunStore.php`: `957` lines.
  - `src/Controller/BackendController.php`: `770` lines.
  - `src/Setup/SetupRunner.php`: `750` lines.
  - `src/Setup/SetupPreflightChecker.php`: `575` lines.
  - `src/Entity/ContentItem.php`: `575` lines.
  - `src/Core/Package/PackageValidator.php`: `529` lines.
- Source domain counts:
  - `Core`: `207` PHP files.
  - `Setup`: `36`.
  - `Security`: `31`.
  - `View`: `30`.
  - `Content`: `25`.
  - `Scheduler`: `24`.
  - `Entity`: `18`.
  - `Controller`: `15`.
  - `Backend`: `13`.
  - `Command`: `10`.
- Class/callable signature signals across `src/` and `tests/`: `3224`.

## Work Plan

1. Align project rules and audit scope.
2. Build a callable and file-size inventory by domain.
3. Review domains in slices: Core package/operation/statistics/logging/config, Setup, Security, Controller/Backend, Content/Schema, Scheduler, View/Twig, Navigation, Entity/Repository, Commands, Assets/Templates/Docs.
4. Challenge previous architectural decisions, especially UUIDs, visitor/request identity, sessions, statistics aggregation, package abstractions, and custom helpers that overlap Symfony/vendor capabilities.
5. Record findings by priority: now, before API, before Security, before Admin/Editor, before release, later.
6. Implement only safe, scoped improvements during the audit branch; split larger refactors into follow-up issues or dedicated PR slices.
7. Keep worklog, class map, docs, and verification notes aligned with actual changes.

## Open Decision Questions

- Are UUID primary identifiers still the right default for all entities, or should internal entities move to auto-increment IDs plus public UUIDs/slugs where needed?
- Should request and visitor IDs become shorter, longer, deterministic, or HMAC-derived differently?
- Can unique visitors be identified more deterministically and stable?
- Should session tokens be additionally bound to a visitor/client signal, and what privacy/usability tradeoff is acceptable?
- Which custom abstractions should move closer to Symfony components before API and Security work expands their public surface?
- Which large classes deserve immediate splitting before more features depend on their current boundaries?
