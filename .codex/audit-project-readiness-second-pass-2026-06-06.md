# Project Readiness Drift Audit Second Pass 2026-06-06

> **Status**: Active  
> **Issue**: #57  
> **Branch**: `audit-project-readiness`  
> **Purpose**: Re-audit the optimized branch after the first implementation wave, verify that audit decisions were actually applied beyond the obvious candidates, and catch review-edge drift before PR review.

## Brief

Run a second complete project-readiness audit without treating the first audit fixes as automatically correct. Re-check the current codebase by domain and challenge decisions around modularity, public API naming, Symfony alignment, test shape, data-model choices, statistics identity, visitor/request IDs, session hardening, platform support, security boundaries, documentation drift, and package/module extension contracts.

This second pass additionally checks the explicit final-gate rules added during the first pass:

- `Studio`/`studio` is product branding or a deliberate legacy UI surface, not the default technical naming scheme. New/refactored system-owned technical identifiers should use the `system` owner/scope convention.
- Runtime errors, validation failures, operational diagnostics, and user-facing feedback should use the shared Message/WorkflowResult/MessageException layer wherever practical.
- Hard exceptions with literal text must be deliberate low-level invariants or unrecoverable adapter failures, not convenience control flow.
- Message code/key catalogues must remain domain-owned and namespace/scope-bound, with central registries acting only as aggregators.
- Available languages must be discovered dynamically except for intentional localized content variants.
- Review likely adjacent paths, not just the changed line or obvious class.

## Ground Rules

- Modular code is preferred over monolithic classes. Large files should be split when the boundary improves responsibility, reuse, or context stability.
- Public callables, interfaces, hooks, events, commands, routes, payloads, and extension points must be easy to name, document, and understand.
- Symfony and maintained vendor capabilities should be preferred over custom abstractions unless the custom layer provides clear project value.
- Tests should protect behavior and platform/security guarantees without overfitting volatile templates, CSS, or implementation details.
- Performance and security decisions must be challenged, including identifier strategy, visitor/request identity, sessions, audit/statistics storage, indexing, pagination, process spawning, package validation, and filesystem scans.
- Findings should include area, evidence, impact, recommendation, priority, and whether a small safe fix was applied immediately.

## Initial Inventory

- Relevant project files across source, tests, assets, templates, config, drafts, manuals, and docs: `2,501`.
- PHP files in `src/` and `tests/`: `784`.
- PHP lines in `src/` and `tests/`: `85,017`.
- Largest current audit candidates:
  - `tests/Controller/AdminUserControllerTest.php`: `1,922` lines.
  - `tests/Controller/UserControllerTest.php`: `1,257` lines.
  - `tests/Controller/BackendControllerTest.php`: `1,216` lines.
  - `tests/Core/Package/PackageValidatorTest.php`: `967` lines.
  - `tests/Setup/SetupRunnerTest.php`: `871` lines.
  - `tests/Scheduler/SchedulerRunnerTest.php`: `766` lines.
  - `tests/Core/Package/PackageLifecycleBoundaryTest.php`: `743` lines.
  - `tests/Navigation/NavigationBuilderTest.php`: `577` lines.
  - `tests/Core/Package/PackageZipInstallerTest.php`: `525` lines.
  - `src/Controller/AdminUserInvitationController.php`: `510` lines.
  - `src/Core/Package/PackageActivator.php`: `507` lines.
  - `src/Core/Package/PackageSchedulerCronInspector.php`: `494` lines.
  - `src/Controller/UserRegistrationController.php`: `460` lines.
  - `src/Core/Package/PackageRemover.php`: `439` lines.
  - `src/Controller/AdminUserController.php`: `422` lines.
  - `src/Controller/UserController.php`: `415` lines.
  - `src/Core/Package/PackageRegistryHandler.php`: `378` lines.
  - `src/Backend/PackageAdminDetailProvider.php`: `370` lines.
  - `src/Entity/ExtensionPackage.php`: `341` lines.
  - `src/Setup/SetupCliInputFactory.php`: `338` lines.
- Source domain counts:
  - `Core`: `282` PHP files.
  - `Setup`: `58`.
  - `Security`: `45`.
  - `View`: `37`.
  - `Content`: `34`.
  - `Scheduler`: `30`.
  - `Backend`: `20`.
  - `Entity`: `18`.
  - `Controller`: `17`.
  - `Command`: `10`.
  - `Form`: `10`.
  - `Navigation`: `10`.
  - `Localization`: `6`.
  - `Database`: `5`.
  - `Mail`: `5`.
  - `Repository`: `3`.
  - `Debug`: `1`.
  - `Editor`: `0`.
  - `Integration`: `0`.
  - `Operations`: `0`.

## Coverage Log

### Production Inventory Baseline

- Production files under `src/`: `592`.
- Production PHP files under `src/`: `592`.
- Production PHP lines under `src/`: `54,372`.
- Production class/interface/trait/enum signature matches under `src/`: `594`.
- Production public method/static method/constant signature matches under `src/`: `2,748`.
- Public extension/entry-point signal matches under `src/`: `224`.
- Current confidence: second pass started; no domain is complete until the domain progress table says `Reviewed`.

### Domain Progress

| Domain | Scope | Status | Notes |
| --- | --- | --- | --- |
| Backend | `src/Backend` | Pending | Re-check read-model split, package detail provider size, dynamic context naming, and hard exceptions. |
| Command | `src/Command` | Pending | Re-check command names, output renderer usage, subprocess boundaries, and `studio:` command branding decision. |
| Content | `src/Content` | Pending | Re-check content aggregate split, language handling, route/error behavior, and future API read-model boundaries. |
| Controller | `src/Controller` | Pending | Re-check controller-as-adapter rule, account/password/token flows, admin user controllers, setup/backend leftovers, and tests. |
| Core primitives | `src/Core/Access`, `ActionLog`, `Config`, `Diff`, `DryRun`, `Message`, `Workflow` | Pending | Re-check config default fallbacks, message catalogues, hard throws, and public naming. |
| Core operations | `src/Core/Filesystem`, `Operation`, `Process`, `Messenger` | Pending | Re-check central process policy, filesystem/symlink handling, Windows edges, and operation message usage. |
| Core package | `src/Core/Package` | Pending | Re-check package policy coverage, lifecycle transitions, package class/file boundaries, namespace validation, and large package services. |
| Core observability | `src/Core/Log`, `Statistics`, `Diagnostics` | Pending | Re-check visitor/request IDs, privacy boundaries, log retention assumptions, and `studio` channel naming. |
| Core support | `src/Core/Translation`, `Lint`, `Manifest`, `Event`, selected support helpers | Pending | Re-check runtime paths, dynamic languages, event providers, and package catalogue conflicts. |
| Database | `src/Database` | Pending | Re-check prefix coverage, raw DBAL paths, and migration portability. |
| Debug and Kernel | `src/Debug`, `src/Kernel.php` | Pending | Re-check debug collector naming, output safety, and APP_DEBUG gating. |
| Entity and Repository | `src/Entity`, `src/Repository` | Pending | Re-check large entities, UID strategy, JSON fields, indexes, and repository filtering boundaries. |
| Form, Mail, Navigation, Localization | `src/Form`, `src/Mail`, `src/Navigation`, `src/Localization` | Pending | Re-check locale resolver, generated form boundaries, mail deferred policy, and navigation facade split. |
| Scheduler | `src/Scheduler` | Pending | Re-check Symfony Scheduler/Lock alignment, task registry shape, GET fallback security, and package task validation. |
| Security | `src/Security` | Pending | Re-check session visitor binding, tokens, API keys, ACL groups, account flows, and secret rotation. |
| Setup | `src/Setup` | Pending | Re-check setup runner/preflight split, dry-run behavior, config defaults, CLI/web input, and setup subprocess handling. |
| View | `src/View` | Pending | Re-check Twig helper split, response header policy, dynamic injection failure ownership, and technical naming. |
| Assets/Templates/Translations | `assets`, `templates`, `translations` | Pending | Re-check hardcoded copy, translation-key coverage, CSS naming convention, and language variants. |
| Documentation | `dev/draft`, `dev/manual`, `docs`, `.codex` | Pending | Re-check drift against actual behavior after all second-pass fixes. |

### Review Method

1. Inspect every production PHP domain again, prioritizing changed/refactored areas but still checking small value objects and enums.
2. Check public methods, interfaces, providers, command names, route entry points, hook names, message codes/keys, Twig helpers, config keys, cookie names, and payload names for clear documentation-friendly naming.
3. Run cross-cutting scans for large files, direct exceptions, literal user-facing strings, hardcoded language variants, direct subprocess starts, direct filesystem mutation, `studio` technical identifiers, public extension points, and service-discovery risks.
4. Record refactoring candidates separately from immediately safe fixes.
5. Prefer Symfony-native/vendor-backed mechanisms where they reduce custom maintenance without hiding project-specific behavior.
6. Track security, privacy, platform, and performance edges even when they are deferred to API/Security/Editor work.
7. After fixes, re-run documentation and test-alignment checks so this second pass verifies the optimized result, not only the starting state.

## Work Plan

1. Build fresh inventories and cross-cutting scan queues.
2. Re-check first-pass finding decisions D1-D47 against the actual current branch and record remaining gaps.
3. Review domains in slices: Core package/operation/statistics/logging/config, Setup, Security, Controller/Backend, Content/Schema, Scheduler, View/Twig, Navigation, Entity/Repository, Commands, Assets/Templates/Translations, Documentation.
4. Challenge previous architectural decisions again, especially account-flow controller size, package lifecycle policy completeness, UID/storage tradeoffs, visitor/session hardening, package policy bypasses, Message-layer consistency, and system/studio naming.
5. Record findings by priority: now, before API, before Security, before Admin/Editor, before release, later/deferred.
6. Apply small safe improvements directly and update tests, docs, class map, and worklog in the same slice.
7. Keep this file as the canonical second-pass progress log over context switches.

## Findings

### S2-001 Internal technical identifiers still use the product-brand `studio` namespace

- **Area:** Service container tags, scheduler locks, secret fingerprint context labels, DBAL setup parameters, Messenger drain marker files.
- **Finding:** The first pass introduced the rule that `Studio`/`studio` is branding, not the default technical owner namespace, but several internal-only identifiers still used `studio.*` or `studio-*` after adjacent refactors.
- **Evidence:** `config/services.yaml` service tags for package settings, live operations, scheduler providers/executors, and view-injection providers; `src/Scheduler/SchedulerLockFactory.php`; `src/Security/AppSecretRotationGuard.php`; `src/Core/Messenger/DeferredMessengerDrain.php`; `src/Setup/SetupDatabaseConnectionFactory.php`; `src/Database/PrefixedConnection.php`.
- **Impact:** The behavior was not broken, but it contradicted the new naming rule and would be easy for review to flag as inconsistent with future package-owned conventions.
- **Recommendation:** Use `system.*` or `system-*` for internal owner/scope names that are not intentionally user-facing branding, CLI product commands, CSS legacy classes, or public documentation labels.
- **Fix applied:** Renamed internal service tags, Scheduler lock keys, APP_SECRET fingerprint context, DBAL wrapper parameters, and Messenger/Scheduler PID/lock marker filenames to `system.*`/`system-*`; updated direct tests.
- **Priority:** Now / Review readiness.

### S2-002 Setup input invariant failures still use literal `InvalidArgumentException` text

- **Area:** Setup input DTO and setup input assertion boundary.
- **Finding:** `SetupInput` and `SetupInputValidator::assertValidInput()` still throw literal `InvalidArgumentException` messages for setup input invariants, while setup already has translated form errors and a domain-owned message catalogue.
- **Evidence:** `src/Setup/SetupInput.php:36`, `src/Setup/SetupInput.php:40`, `src/Setup/SetupInput.php:44`, `src/Setup/SetupInput.php:48`, `src/Setup/SetupInput.php:52`, `src/Setup/SetupInput.php:56`, `src/Setup/SetupInput.php:60`, `src/Setup/SetupInputValidator.php:112`, `src/Setup/SetupInputValidator.php:116`, `src/Setup/SetupInputValidator.php:120`, `src/Setup/SetupInputValidator.php:124`.
- **Impact:** This is recoverable setup validation, not a low-level adapter invariant. Review may reasonably ask why these paths bypass the Message layer while adjacent content/entity validation already uses `MessageException`.
- **Recommendation:** Add setup-owned message keys for input invariant failures or reshape setup creation so `SetupInputValidator` returns keyed errors before a DTO is created. Avoid adding convenience literal throws where setup can surface structured diagnostics.
- **Fix applied:** Added setup-owned message keys for input and setup-step failures, converted `SetupInput`, `SetupInputValidator`, `SetupLanguageSelector`, `SetupComposerCommandResolver`, `DatabaseUrlFactory`, `SetupDatabaseConnectionFactory`, and setup runtime command/PHP-CLI failure boundaries to structured `MessageException` or `SetupStepFailedException::fromMessage()` usage, and updated adjacent tests.
- **Priority:** Now / Review readiness.

## Cross-Cutting Passes

- Fresh file and large-file inventory captured.
- `studio` technical naming scan started; raw results include intentional branding/UI CSS/command/log-channel uses and require classification.
- Direct hard-exception scan rerun with fixed-string searches. Most remaining hard exceptions are low-level value-object, filesystem, lint, checksum, or manifest invariants; setup input validation remains the first likely Message-layer gap.
- Setup failure scan rechecked after S2-002: old free setup input/failure texts were removed from primary throw paths. `LiveOperationStarter` still uses local runtime exceptions for unrecoverable process-start adapter failures that are immediately caught and reported as `operation.start_failed`, so it is currently treated as deliberate adapter behavior.

## Fixes Applied

- Renamed internal system-owned technical identifiers away from `studio` to `system` for service tags, scheduler locks, secret fingerprint labels, DBAL connection wrapper parameters, and Messenger/Scheduler marker files.
- Converted setup input validation and setup-step failure boundaries from free-text hard exceptions to domain-owned Message-layer keys while keeping hard invariant behavior.
