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
| Controller | `src/Controller` | In progress | Account registration/invitation flows reviewed first; S2-003 records the remaining controller-as-adapter gap. Setup/backend leftovers still need review. |
| Core primitives | `src/Core/Access`, `ActionLog`, `Config`, `Diff`, `DryRun`, `Message`, `Workflow` | Pending | Re-check config default fallbacks, message catalogues, hard throws, and public naming. |
| Core operations | `src/Core/Filesystem`, `Operation`, `Process`, `Messenger` | Pending | Re-check central process policy, filesystem/symlink handling, Windows edges, and operation message usage. |
| Core package | `src/Core/Package` | In progress | `PackageActivator`, `PackageRemover`, registry sync, fault reset, runtime loader, package install apply, scheduler cron validation, and PHP capability policy reviewed. S2-004 keeps cron parser behavior, S2-006 hardens dynamic callable bypasses, and S2-007 records the remaining lifecycle transaction boundary. |
| Core observability | `src/Core/Log`, `Statistics`, `Diagnostics` | Pending | Re-check visitor/request IDs, privacy boundaries, log retention assumptions, and `studio` channel naming. |
| Core support | `src/Core/Translation`, `Lint`, `Manifest`, `Event`, selected support helpers | Pending | Re-check runtime paths, dynamic languages, event providers, and package catalogue conflicts. |
| Database | `src/Database` | Pending | Re-check prefix coverage, raw DBAL paths, and migration portability. |
| Debug and Kernel | `src/Debug`, `src/Kernel.php` | Pending | Re-check debug collector naming, output safety, and APP_DEBUG gating. |
| Entity and Repository | `src/Entity`, `src/Repository` | Pending | Re-check large entities, UID strategy, JSON fields, indexes, and repository filtering boundaries. |
| Form, Mail, Navigation, Localization | `src/Form`, `src/Mail`, `src/Navigation`, `src/Localization` | Pending | Re-check locale resolver, generated form boundaries, mail deferred policy, and navigation facade split. |
| Scheduler | `src/Scheduler` | Pending | Re-check Symfony Scheduler/Lock alignment, task registry shape, GET fallback security, and package task validation. |
| Security | `src/Security` | In progress | Session visitor binding reviewed; S2-008 records the remaining copied-session plus copied-visitor-cookie limitation. Tokens, API keys, ACL groups, account flows, and secret rotation still need broader review. |
| Setup | `src/Setup` | Pending | Re-check setup runner/preflight split, dry-run behavior, config defaults, CLI/web input, and setup subprocess handling. |
| View | `src/View` | In progress | Template runtime fallback reviewed; S2-005 removes a hardcoded `en` fallback from the root layout. Twig helper split, response header policy, dynamic injection failure ownership, and technical naming still need broader review. |
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

### S2-003 Account registration and invitation controllers still own application workflow

- **Area:** Account registration, invitation, registration approval, token reissue/revoke, account-link acceptance.
- **Finding:** The first pass split several backend/controller flows, but `AdminUserInvitationController` and `UserRegistrationController` still combine HTTP concerns with application workflow decisions, user/token lookup, ACL group repair, role/group policy checks, mail delivery, audit logging, state markers, and persistence.
- **Evidence:** `src/Controller/AdminUserInvitationController.php:45`, `src/Controller/AdminUserInvitationController.php:68`, `src/Controller/AdminUserInvitationController.php:145`, `src/Controller/AdminUserInvitationController.php:216`, `src/Controller/AdminUserInvitationController.php:290`, `src/Controller/UserRegistrationController.php:57`, `src/Controller/UserRegistrationController.php:91`, `src/Controller/UserRegistrationController.php:181`, `src/Controller/UserRegistrationController.php:296`.
- **Impact:** Behavior is currently covered and not obviously unsafe, but the controllers remain large and security-sensitive. Review can reasonably ask for the same controller-as-adapter standard used elsewhere, and future Security/API work will otherwise duplicate these account workflows.
- **Recommendation:** Extract focused application services such as `AdminAccountInvitationService`, `AccountTokenReissueService`, `PublicRegistrationService`, and `AccountLinkAcceptanceService` returning keyed result objects or `WorkflowResult`s. Keep controllers responsible for CSRF, request/response shape, redirects, and rendering only.
- **Fix applied:** Deferred to an account-flow refactor slice unless this second pass has enough remaining capacity after completing all domain checks.
- **Priority:** Before Security / Review consideration.

### S2-004 Package scheduler cron inspection is large but blocks dynamic cron policy bypasses

- **Area:** Package validation and scheduler task contribution policy.
- **Finding:** `PackageSchedulerCronInspector` is a custom static parser and remains above the preferred file-size target, but its current validator path treats unparseable/dynamic `defaultCronExpression` values as invalid instead of silently allowing them.
- **Evidence:** `src/Core/Package/PackageSchedulerCronInspector.php:12`, `src/Core/Package/PackageSchedulerCronInspector.php:23`, `src/Core/Package/PackageSchedulerCronValidator.php:39`, `tests/Core/Package/PackageValidatorTest.php:544`, `tests/Core/Package/PackageValidatorTest.php:562`.
- **Impact:** The custom parser is maintenance-heavy, but the security/product behavior is intentionally conservative: package scheduler definitions must use literal cron expressions that can be validated during package activation.
- **Recommendation:** Keep current behavior for this branch. Consider a later smaller parser/extractor split or an explicit package-contribution DSL once package service loading matures, but do not weaken validation by accepting dynamic cron expressions.
- **Fix applied:** None needed.
- **Priority:** Later / Monitor.

### S2-005 Root template language fallback hardcoded English outside locale discovery

- **Area:** Twig base layout and view context.
- **Finding:** The root HTML template used `en` when rendering without a request. This is small, but it violates the new dynamic-language rule because the default language already has a runtime resolver backed by config and discovered translation catalogues.
- **Evidence:** `templates/base.html.twig:2`, `src/View/ViewContextProvider.php:24`.
- **Impact:** Normal HTTP rendering already uses the request locale, so user-facing impact is low. Request-less/error-adjacent rendering and review scans would still see a hardcoded language assumption.
- **Recommendation:** Surface the resolved default locale through the existing view context and let the template consume that fallback only when no request exists.
- **Fix applied:** Added `default_locale` to `ViewContextProvider` from `ContentRouteLocalization::defaultLanguage()` and changed the base template fallback to `studio_view_context().default_locale`.
- **Priority:** Now / Review readiness.

### S2-006 Package PHP capability policy misses dynamic callable bypasses

- **Area:** Installable package PHP validation and package runtime loader safety.
- **Finding:** Direct blocked PHP functions and classes were detected, but a package could still hide a blocked function behind variable function calls or generic callable dispatch such as `call_user_func()`.
- **Evidence:** `src/Core/Package/PackagePhpCapabilityPolicy.php:23`, `src/Core/Package/PackagePhpCapabilityPolicy.php:120`, `src/Core/Package/PackagePhpLoader.php:128`.
- **Impact:** Because package PHP is executed by `PackagePhpLoader`, this weakens the product decision that package activation validation is the deterministic security boundary for package-owned code. A crafted package could perform direct filesystem/process/environment work even though literal calls are blocked.
- **Recommendation:** Treat dynamic callables and reflection/introspection primitives as blocked capabilities for installable packages. Packages that need system operations should use explicit extension points instead of arbitrary PHP indirection.
- **Fix applied:** Blocked variable function calls, `call_user_func*()`, `forward_static_call*()`, and core reflection classes in the package PHP capability policy; added a regression test for dynamic callable bypass patterns.
- **Priority:** Now / Security review readiness.

### S2-007 Package lifecycle operations need a clearer atomicity boundary

- **Area:** Package activation, removal, install replacement, registry sync, runtime faults, and asset rebuild handoff.
- **Finding:** Package lifecycle classes now report structured results well, but they still interleave status changes, Doctrine flushes, filesystem operations, cleanup, and asset rebuild dispatch in several classes without one reusable lifecycle transaction/rollback coordinator.
- **Evidence:** `src/Core/Package/PackageActivator.php:328`, `src/Core/Package/PackageRemover.php:89`, `src/Core/Package/PackageRegistryHandler.php:35`, `src/Core/Package/Install/PackageInstallApplier.php:32`, `src/Core/Package/PackageRuntimeFailureHandler.php:41`.
- **Impact:** Current behavior is functional and covered, but edge failures around DB flush, filesystem replacement/removal, cleanup, and rebuild dispatch are hard to reason about uniformly. Review may flag duplicated rollback status handling and partial-state risk.
- **Recommendation:** Extract a `PackageLifecycleCoordinator`/operation journal that owns status snapshots, DB flush boundaries, filesystem rollback callbacks, rebuild dispatch/fallback, and common lifecycle messages. Keep actual install/remove/activate classes as orchestrators over that shared coordinator.
- **Fix applied:** Deferred; this is a larger refactor and should be a dedicated package-lifecycle slice after the second pass completes.
- **Priority:** Before package marketplace / Before release.

### S2-008 Session visitor binding detects session-only duplication but not copied visitor cookies

- **Area:** Authenticated session hardening, visitor IDs, and future Security work.
- **Finding:** The current binding correctly terminates authenticated sessions when the stored session visitor ID no longer matches the current first-party visitor ID. That catches copied session cookies when the `system_visitor` cookie is absent or different. It cannot, by design, reliably distinguish a user from an attacker who copied both the Symfony session cookie and the first-party visitor cookie.
- **Evidence:** `src/Security/SessionVisitorBindingSubscriber.php:50`, `src/Security/SessionVisitorBindingSubscriber.php:78`, `src/Core/Statistics/VisitorIdGenerator.php:119`, `tests/Security/SessionVisitorBindingSubscriberTest.php:56`, `dev/draft/0.2.x-SecurityAccessControl.md:161`.
- **Impact:** The branch should not overclaim complete session-duplication protection. Adding hard IP or user-agent binding now would increase false positives for legitimate users on shared/mobile networks or after browser/device updates.
- **Recommendation:** Keep hard termination on visitor mismatch. In the Security feature branch, add soft risk signals for same-session multi-visitor, rapid IP/UA drift, concurrent use, and admin action step-up. Consider storing a session fingerprint that can trigger re-authentication instead of unconditional termination when only soft signals change.
- **Fix applied:** None in this slice; documented as a Security follow-up and review note.
- **Priority:** Security feature branch / Before release.

## Cross-Cutting Passes

- Fresh file and large-file inventory captured.
- `studio` technical naming scan started; raw results include intentional branding/UI CSS/command/log-channel uses and require classification.
- Direct hard-exception scan rerun with fixed-string searches. Most remaining hard exceptions are low-level value-object, filesystem, lint, checksum, or manifest invariants; setup input validation remains the first likely Message-layer gap.
- Setup failure scan rechecked after S2-002: old free setup input/failure texts were removed from primary throw paths. `LiveOperationStarter` still uses local runtime exceptions for unrecoverable process-start adapter failures that are immediately caught and reported as `operation.start_failed`, so it is currently treated as deliberate adapter behavior.
- Large production class review started with account-flow controllers, package lifecycle activation/removal/registry/install, runtime loader, and package scheduler cron inspection. Account-flow controllers are the first remaining controller-as-adapter gap; package scheduler cron validation is large but conservative and currently blocks dynamic cron bypasses.
- Hardcoded language scan reviewed. Remaining `en`/`de` references are currently package translation fallback policy, content seed variants, test fixtures, or code-editor language identifiers except for S2-005.
- Package policy bypass scan reviewed. Direct file/process/env/network calls, include/require/eval, reserved package paths, source namespaces, translation namespaces, symlink zip entries, scheduler cron literals, and source paths are validated; S2-006 closes the dynamic callable gap.
- Session visitor binding reviewed. Hard binding works for missing/different visitor cookies, but complete cookie-pair duplication remains a deferred risk-scoring problem rather than a safe hard-termination signal today.

## Fixes Applied

- Renamed internal system-owned technical identifiers away from `studio` to `system` for service tags, scheduler locks, secret fingerprint labels, DBAL connection wrapper parameters, and Messenger/Scheduler marker files.
- Converted setup input validation and setup-step failure boundaries from free-text hard exceptions to domain-owned Message-layer keys while keeping hard invariant behavior.
- Removed the hardcoded root-template English fallback by surfacing the resolved default locale through the view context.
- Hardened installable package PHP validation against dynamic callable and reflection bypasses.
