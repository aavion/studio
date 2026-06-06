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
| Backend | `src/Backend` | In progress | Admin settings/system-info path and backend view context reviewed. System-info uses a reduced admin-only report, not raw `phpinfo()` or `$_SERVER`; S2-021 renames internal backend form request attributes to `system`. S2-027 splits package detail file/link/dependency helpers out of the package detail read-model assembler. Remaining pass: backend route/action naming and controller adapters. |
| Command | `src/Command` | Reviewed | Commands are small and use `studio:` as intentional product CLI branding. Process-heavy work delegates into services; no immediate command naming drift found. |
| Content | `src/Content` | Reviewed | Content read resolution, routing language behavior, content field locale tokens, public custom-Twig rendering, redirects, schema primitives, and content event payloads reviewed. S2-015 hardens regional locale fallback and persisted field locale compatibility; S2-037 reports custom-Twig render failures through the Message layer before falling back. |
| Controller | `src/Controller` | In progress | Account registration/invitation/profile/password flows reviewed first; S2-003 records the remaining controller-as-adapter gap, S2-009 hardens profile language persistence, and S2-028 centralizes repeated token/password helper logic. Setup/backend leftovers still need review. |
| Core primitives | `src/Core/Access`, `ActionLog`, `Config`, `Diff`, `DryRun`, `Message`, `Workflow` | Reviewed | Config seed/default fallback, domain-owned Message code/key aggregation, access rules, ActionLog, Diff, DryRun, Message, and Workflow value-object invariants reviewed. Hard exceptions in this slice are deliberate low-level invariant guards, while recoverable runtime config failures already report through the Message layer. |
| Core operations | `src/Core/Filesystem`, `Operation`, `Process`, `Messenger` | Reviewed | Process environment, detached process boundaries, filesystem actions, Messenger drain, live-operation start/storage/runner boundaries, PHP CLI resolver/preference validation, and file inventory scanning reviewed. Dotenv app values are passed to child processes while web/CGI context is filtered. Filesystem symlink guards use WorkflowResults; S2-017 converts live-operation start failure reasons to Message-layer diagnostics, S2-030 normalizes live-operation storage roots, and S2-035 normalizes file-inventory roots. |
| Core package | `src/Core/Package` | In progress | `PackageActivator`, `PackageRemover`, registry sync, fault reset, runtime loader, package install apply, scheduler cron validation, PHP capability policy, runtime contribution registry, and asset registry contributions reviewed. S2-004 keeps cron parser behavior, S2-006 hardens dynamic callable bypasses, S2-007 records the remaining lifecycle transaction boundary, S2-010 converts package runtime contribution failures to Message-layer diagnostics, and S2-026 converts asset contribution invariants to Package Message keys. |
| Core observability | `src/Core/Log`, `Statistics`, `Diagnostics` | Reviewed | Visitor/request ID, access metadata sanitization, statistics recorder/aggregator/store, log parsing/filtering/presentation, audit/operation/message logging, and reduced system diagnostics reviewed. S2-016 hardens snapshot temp-file writes, S2-025 renames internal log channels/files to `system_*`, S2-031 validates statistics trace IDs, and S2-034 normalizes statistics-store paths plus deterministic system-info extension output. Public CSS/UI names remain product-facing. |
| Core support | `src/Core/Translation`, `Lint`, `Manifest`, `Event`, selected support helpers | Reviewed | Event hook registry, translation/runtime paths, package catalogue collision handling, lint temp files, manifest specs/parsing/validation, and low-level lint/manifest value-object invariants reviewed. S2-011 prevents silent public hook descriptor overrides, S2-019 renames an internal lint temp prefix to `system-*`, and S2-036 stabilizes translation source/runtime path ordering and separator handling. |
| Database | `src/Database` | Reviewed | Table-prefix coverage, raw DBAL wrapper prefixing, Doctrine metadata prefixing, and migration portability reviewed. `studio_` remains a user-facing/product example prefix, while internal DBAL wrapper params use `system_*`. |
| Debug and Kernel | `src/Debug`, `src/Kernel.php` | Reviewed | Debug collector naming, output safety, and APP_DEBUG gating reviewed. S2-021 renames the internal collector and debug HTML comment to `system`; public Twig helper names remain `studio_*` as theme-facing API. |
| Entity and Repository | `src/Entity`, `src/Repository` | Reviewed | Entity inventory, UID storage, statistics indexes, content field locale token compatibility, security/account token entities, state markers, config/package settings, site menus, and repository filtering boundaries reviewed. UUIDv7 RFC 4122 strings remain the portable pre-1.0 tradeoff; S2-031 validates statistics trace IDs, S2-032 moves state marker metadata errors to State messages, and S2-033 validates persisted navigation targets. |
| Form, Mail, Navigation, Localization | `src/Form`, `src/Mail`, `src/Navigation`, `src/Localization` | Reviewed | Locale resolver, form builder/submission layer, mail locale behavior, and navigation label fallback reviewed. S2-013 records the deferred Mail Message/API hardening; S2-014 hardens navigation primary-language fallback. |
| Scheduler | `src/Scheduler` | Reviewed | Scheduler task registry, lock naming, package task policy, run recorder, web-auth settings, task definitions, `/cron/run` controller behavior, direct job triggering, and docs alignment reviewed. S2-012 converts public task definition invariants to Message-layer diagnostics; GET-token auth remains opt-in, Bearer auth stays primary, and only read-write API keys owned by active admin/owner users can trigger web runs. |
| Security | `src/Security` | Reviewed | Session visitor binding, AccountToken issuer/entity behavior, API-key vault/entity behavior, ACL group policies/apply operations, maintenance-mode HTTP flow, APP_SECRET rotation guard, mail-link delivery stub, and remember-me direction reviewed. S2-008 records the remaining copied-session plus copied-visitor-cookie limitation, S2-018 captures remember-me as a Security-branch feature candidate using server-side rotating tokens bound to the visitor cookie, S2-028 extracts shared account token/password helpers, and S2-038 removes separate plain-token logging from the mail-link debug stub. Account-flow controller extraction remains tracked in the Controller domain by S2-003. |
| Setup | `src/Setup` | Reviewed | PHP-CLI resolver/preference flow, dry-run placeholder behavior, preflight failure mapping, Composer probe, setup subprocess environment, setup seeding, environment writing/rollback, web/CLI input validation, and setup class sizes reviewed. S2-039 splits CLI database input resolution out of the oversized CLI input factory; all setup production files are now below the 300-line target. |
| View | `src/View` | Reviewed | Template runtime fallback, Markdown rendering/embed output, package macro/template paths, system package metadata, Twig helper ownership, dynamic/static view injection registry, response header/output hooks, HTTP error rendering, and dynamic injection failure reporting reviewed. S2-005 removes a hardcoded `en` fallback from the root layout, S2-023 moves Markdown embed accessibility copy to translations, and S2-024 converts unsupported template namespace failures to View Message keys. Public `studio_*` Twig helper names remain intentional product/theme API; internal technical naming stays under `system`. |
| Assets/Templates/Translations | `assets`, `templates`, `translations` | In progress | Hardcoded language variants, package translation fallback policy, and active setup templates reviewed. S2-022 replaces the package `languages/en` special case with a configured fallback-locale requirement. S2-023 fixes Markdown embed UI copy. S2-040 splits the setup wizard render target into focused partials. Remaining pass: broader CSS naming classification. |
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

### S2-009 Profile language accepts unsupported crafted POST values

- **Area:** User profile settings and dynamic language handling.
- **Finding:** The profile form renders dynamic language options, but the controller persisted any submitted `language` value. The locale resolver later falls back safely, but unsupported settings should not be stored at all.
- **Evidence:** `src/Controller/UserController.php:132`, `templates/frontend/user/profile.html.twig:57`, `tests/Controller/UserProfileControllerTest.php:109`.
- **Impact:** A crafted request could store stale or unsupported locale tokens in a user profile. This does not break rendering because `LocalePreferenceResolver` validates before use, but it creates avoidable configuration drift and undermines dynamic language guarantees.
- **Recommendation:** Accept only `default` or a currently available locale from `LocalePreferenceResolver::availableLocales()` and return a translated profile validation error otherwise.
- **Fix applied:** Added controller validation, `ui.user.profile.errors.language_invalid` translations, runtime catalogue entries, and a crafted POST regression test.
- **Priority:** Now / Review readiness.

### S2-010 Package runtime contribution failures use literal exception text

- **Area:** Package PHP loader, package runtime contribution registry, scheduler contribution policy.
- **Finding:** `PackageRuntimeContributionRegistry` correctly rejects unsupported contribution types, elevated scheduler sources, and trusted package scheduler tasks, but the failures used literal `InvalidArgumentException` messages.
- **Evidence:** `src/Core/Package/PackageRuntimeContributionRegistry.php:162`, `src/Core/Package/PackageRuntimeContributionRegistry.php:181`, `src/Core/Package/PackagePhpLoader.php:212`, `tests/Core/Package/PackageLifecycleBoundaryTest.php:249`.
- **Impact:** The package is marked faulty and behavior remains safe, but package authors and operational logs lose the domain-owned Message key that explains which policy failed. This is exactly the kind of review edge the second pass should remove.
- **Recommendation:** Use package-owned Message keys for contribution shape and scheduler privilege failures, and preserve the previous Message context when the PHP loader converts the error into a package fault.
- **Fix applied:** Added package runtime/scheduler Message keys and translations, converted registry policy errors to `MessageException`, enriched `PackagePhpLoader` fault context with previous Message details, and added regression expectations.
- **Priority:** Now / Review readiness.

### S2-011 Public event hook descriptors can be silently overridden by later providers

- **Area:** Public event hook registry and future package hook aggregation.
- **Finding:** `PublicEventHookRegistry` keyed descriptors by event class, so a later provider could silently replace an earlier provider's descriptor for the same public event.
- **Evidence:** `src/Core/Event/PublicEventHookRegistry.php:30`, `tests/Core/Event/PublicEventHookRegistryTest.php:64`.
- **Impact:** Current system providers do not intentionally collide, so no behavior was broken today. Future package/domain aggregation would otherwise allow a package provider or misordered domain provider to redefine a system-owned hook's domain, mode, or mutability without any explicit policy.
- **Recommendation:** Make conflict behavior deterministic and conservative: the first descriptor wins. With system providers ordered before package providers, system-owned hook metadata cannot be silently overridden.
- **Fix applied:** Changed registry aggregation to keep the first descriptor per event class and added a regression test for duplicate providers.
- **Priority:** Now / Review readiness.

### S2-012 Scheduler task definition invariants use literal exception text

- **Area:** Scheduler task definitions and package/system scheduler extension points.
- **Finding:** `SchedulerTaskDefinition` is a public extension-point value object used by system providers and package runtime contributions, but invalid identifiers, translation keys, targets, cron expressions, sources, and metadata used literal `InvalidArgumentException` messages.
- **Evidence:** `src/Scheduler/SchedulerTaskDefinition.php:24`, `src/Scheduler/SchedulerTaskDefinition.php:31`, `src/Scheduler/SchedulerTaskDefinition.php:113`, `tests/Scheduler/SchedulerRunnerTest.php:411`.
- **Impact:** The hard invariant behavior is correct, but package authors and operational tooling benefit from stable domain Message keys. Literal texts here also conflicted with the second-pass rule that hard throws should be deliberate and structured where practical.
- **Recommendation:** Keep the invariant boundary, but emit Scheduler-owned Message keys/codes so rejected package/system task definitions are diagnosable and translatable through the shared Message layer.
- **Fix applied:** Added Scheduler task-definition Message keys/translations, converted `SchedulerTaskDefinition` to `MessageException`, and updated a focused regression to assert the translation key.
- **Priority:** Now / Review readiness.

### S2-013 Mail flow definitions still use literal invariants while the real mailer remains deferred

- **Area:** Mail flow registry, mail delivery payload, future mailer settings/API.
- **Finding:** `MailFlowDefinition`, `MailDeliveryMessage`, and `MailFlowRegistry` use literal `InvalidArgumentException`/`LogicException` messages for invalid flow metadata, recipient payloads, locale tokens, and unknown flow lookups.
- **Evidence:** `src/Mail/MailFlowDefinition.php:84`, `src/Mail/MailDeliveryMessage.php:34`, `src/Mail/MailDeliveryMessage.php:139`, `src/Mail/MailFlowRegistry.php:24`, `dev/draft/0.4.x-MailerDeliveryContract.md:29`.
- **Impact:** This is not review-blocking for the current branch because mail delivery is explicitly a future feature and current mail-bound account flows use the message-log stub. However, these classes are the future public mailer contract and should not mature with literal, non-catalogued diagnostics.
- **Recommendation:** In the mailer feature slice, introduce a Mail-owned Message code/key catalogue, provider interface for flow definitions, structured unknown-flow results, and full tests for locale/template fallback, placeholder validation, and safe delivery failures. Keep the existing locale policy: recipient preferred language first, then request locale for public flows or default locale for admin-triggered flows.
- **Fix applied:** Deferred intentionally; a partial Mail Message catalogue now would duplicate the upcoming mailer contract work.
- **Priority:** Mailer feature branch / Before release.

### S2-014 Navigation labels ignore primary-language fallback for regional locales

- **Area:** Navigation item repository and dynamic language handling.
- **Finding:** Persisted menu labels are stored as language-keyed JSON, but lookup used only an exact language key before falling back to the first stored label.
- **Evidence:** `src/Navigation/NavigationItemRepository.php:101`, `tests/Navigation/NavigationBuilderTest.php:18`.
- **Impact:** A request/build language such as `de_DE` would not resolve an available `de` navigation label and could show an unrelated first label instead. This conflicts with the dynamic-language policy and with the resolver behavior used elsewhere.
- **Recommendation:** Normalize `_`/`-`, check exact normalized variants, then fall back to the primary language before using the first available label.
- **Fix applied:** Added normalized and primary-language fallback in `NavigationItemRepository::label()` plus a regression for `de_DE` resolving a `de` label.
- **Priority:** Now / Review readiness.

### S2-015 Content read language fallback and field tokens are narrower than the dynamic locale catalogue

- **Area:** Content read context, content field values, dynamic language compatibility.
- **Finding:** Content read resolution used only exact language matches before default/first-language fallback, and persisted `ContentFieldValue` language tokens accepted only a narrow lowercase two-letter plus optional hyphen pattern.
- **Evidence:** `src/Content/Read/ContentReadContextResolver.php:35`, `src/Entity/ContentFieldValue.php:122`, `tests/Localization/LanguageCatalogueDiscoveryTest.php:27`.
- **Impact:** A language catalogue containing regional tokens such as `en_US` is supported by discovery, but content field values could reject those tokens and content reads for `de_DE` could fall back to the wrong default instead of available `de` content.
- **Recommendation:** Use the shared locale-token policy for persisted content field languages and make content reads follow normalized/primary-language fallback before defaulting.
- **Fix applied:** Added normalized, underscore, and primary-language fallback to `ContentReadContextResolver`, switched `ContentFieldValue` validation to `LocaleToken::isValid()`, and added regressions for `de_DE` content reads and `en_US` field values.
- **Priority:** Now / Platform/language compatibility.

### S2-016 Statistics snapshot store uses a fixed temp filename

- **Area:** Access statistics snapshot storage and file-based operational state.
- **Finding:** `FileAccessStatisticsStore` wrote snapshots through a fixed `latest.json.tmp` path before renaming to `latest.json`.
- **Evidence:** `src/Core/Statistics/FileAccessStatisticsStore.php:26`, `tests/Core/Statistics/FileAccessStatisticsStoreTest.php:29`.
- **Impact:** A single writer is fine, but concurrent requests or scheduler/admin refreshes could collide on the same temp file. Failed renames could also leave stale shared temp files behind.
- **Recommendation:** Use a unique temp path per write and clean it up if the final rename fails, matching the existing pattern used by translation and package asset writers.
- **Fix applied:** Added a random suffix to the temporary snapshot path and unlink cleanup on failed rename.
- **Priority:** Now / Platform and concurrency hardening.

### S2-017 Live-operation start failure reasons still used literal exceptions

- **Area:** Live-operation runner startup, PHP CLI resolution, and admin/API operation feedback.
- **Finding:** `LiveOperationStarter` caught startup failures and returned a Message-backed `WorkflowResult`, but the private failure paths still created literal `RuntimeException` messages for runner-start and PHP-CLI-unavailable states.
- **Evidence:** `src/Core/Operation/Live/LiveOperationStarter.php:95`, `src/Core/Operation/Live/LiveOperationStarter.php:107`.
- **Impact:** User-facing behavior was already graceful, but the concrete failure reason was not domain-keyed. Review could reasonably flag this as a convenience throw at a recoverable operational boundary.
- **Recommendation:** Keep the outer `WorkflowResult` contract, but make the inner failure reasons domain-owned MessageExceptions so API/admin polling can receive deterministic translation keys and structured context.
- **Fix applied:** Added `message.operation.runner_start_failed` and `message.operation.php_cli_unavailable`, converted the private startup failures to `MessageException`, and preserved the existing generic fallback for unexpected exceptions.
- **Priority:** Now / Review readiness.

### S2-018 Remember-me login needs server-side token rotation and visitor binding

- **Area:** Login/session hardening and future Security feature work.
- **Finding:** A "keep me logged in" option is useful, but it should not be implemented as a bare long-lived identity cookie. A duplicated remember-me cookie has the same trust problem as a duplicated session cookie unless the server can revoke, rotate, and compare it against additional first-party state.
- **Evidence:** `config/packages/security.yaml`, `src/Security/SessionVisitorBindingSubscriber.php`, `dev/draft/0.2.x-SecurityAccessControl.md`.
- **Impact:** The current branch intentionally keeps session/visitor binding simple. Adding remember-me now would widen the auth surface before the Security branch can design token storage, revocation, audit logging, and step-up behavior coherently.
- **Recommendation:** Defer implementation to the Security feature branch and keep the current normal session behavior unchanged here. Prefer Symfony's remember-me architecture with persistent server-side tokens: the browser stores only an opaque selector/token cookie, the server stores the hashed token plus user, expiry, visitor binding, and revocation state, the trust window is 7 days, automatic use rotates the token value without silently extending the original expiry, explicit credential login with the checkbox can issue a fresh 7-day token, successful auto-login creates a fresh Symfony session, token metadata binds to the current `system_visitor` cookie, manual logout/password/security events revoke the token, and visitor mismatch or token reuse is a hard reject plus audit signal. Backend-calculated signals such as IP buckets, user-agent family, client hints, request cadence, and concurrent use should be soft risk/step-up inputs rather than sole hard identity proof because they change during legitimate use. Keep normal session TTL low only after UX and admin workflows are reviewed; `60` minutes is a reasonable candidate but should be decided in Security.
- **Fix applied:** Deferred and documented as a Security feature-branch decision.
- **Priority:** Security feature branch / Before release.

### S2-019 PHP linter temp files still used product-brand prefix

- **Area:** Core linting support and internal temporary filenames.
- **Finding:** `PhpLinter` used `studio-php-lint-` as a temp-file prefix. This is not user-facing product branding and falls under the new internal technical naming rule.
- **Evidence:** `src/Core/Lint/PhpLinter.php:15`.
- **Impact:** Behavior was unaffected, but review scans for `studio-*` technical identifiers would flag it as avoidable drift.
- **Recommendation:** Use `system-*` for internal temporary filenames owned by the application core.
- **Fix applied:** Renamed the prefix to `system-php-lint-`.
- **Priority:** Now / Naming consistency.

### S2-020 APP_SECRET rotation guard can repeat owner recovery delivery if fingerprint persistence fails

- **Area:** Secret rotation emergency handling, owner recovery links, and API-key revocation.
- **Finding:** `AppSecretRotationGuard` revokes active API keys and issues owner password-reset links when the stored APP_SECRET fingerprint changes. If the new fingerprint cannot be persisted after the recovery handling, later requests can detect the same rotation again. API-key revocation is mostly idempotent, but owner password-reset delivery could repeat.
- **Evidence:** `src/Security/AppSecretRotationGuard.php:81`, `src/Security/AppSecretRotationGuard.php:85`, `src/Security/AppSecretRotationGuard.php:88`, `src/Security/AppSecretRotationGuard.php:175`.
- **Impact:** This is an uncommon degraded-storage edge, but it affects a sensitive emergency flow and could spam owner recovery messages or create noisy account-token churn after a config write failure.
- **Recommendation:** In the dedicated Security hardening slice, add a small server-side rotation handling state or attempt journal that records "handling started" and "handling completed" separately from the final fingerprint. Recovery-link issuance should be idempotent per environment/fingerprint pair, and audit logging should include persistence failure context without making normal public requests repeatedly redo emergency handling.
- **Fix applied:** Deferred; documented as a Security hardening follow-up.
- **Priority:** Security feature branch / Before release.

### S2-021 Internal debug collector and backend form request attributes used product-brand naming

- **Area:** Debug collector, debug HTML comments, backend settings form repopulation, and internal request attributes.
- **Finding:** `StudioDebugCollector`, the `studio-debug` HTML comment, and `_studio_form_values`/`_studio_form_errors` were internal technical names rather than public branding surfaces. Public Twig helpers such as `studio_debug_info()` and CSS classes remain intentional product/theme API.
- **Evidence:** `src/Debug/StudioDebugCollector.php`, `src/View/Http/ResponseHookSubscriber.php`, `src/Controller/BackendController.php:255`, `src/View/Twig/AdminViewTwigExtension.php:192`.
- **Impact:** No behavior was broken, but this was exactly the kind of internal naming drift the final audit rule was meant to catch.
- **Recommendation:** Use `SystemDebugCollector`, `system-debug`, and `_system_*` request attributes for core-owned internals while leaving product-facing Twig/CSS API stable.
- **Fix applied:** Renamed the collector class/file/service references, debug HTML comment marker, tests, class map, drafts, and backend form request attributes.
- **Priority:** Now / Final naming gate.

### S2-022 Package translation fallback validation hardcoded English

- **Area:** Package validation, translation source policy, dynamic language handling.
- **Finding:** Package translation validation required `languages/en/` whenever a package shipped translations. This preserved a deterministic fallback, but it encoded one concrete language into the package policy while the project rules now require available languages and fallbacks to stay dynamic.
- **Evidence:** `src/Core/Package/PackageTranslationNamespaceValidator.php:38`, `tests/Core/Package/PackageValidatorTest.php:781`, `dev/manual/theme-module-developer-guidelines.md:84`.
- **Impact:** Installations with a different configured default/fallback locale would still be forced to ship English package catalogues even when their active language set uses a different fallback. This is not a runtime security bug, but it is platform/product drift and would make future language-package support less coherent.
- **Recommendation:** Keep the conservative requirement that packages with translations must ship a fallback catalogue, but derive the required locale from configuration and accept regional fallback candidates such as `de_DE`, `de-DE`, or primary `de`.
- **Fix applied:** Renamed the Message code/key to `package.translation_fallback_missing`, made `PackageTranslationNamespaceValidator` accept a configurable `$fallbackLocale` injected from `%kernel.default_locale%`, added regional/primary fallback candidates, updated tests, translations, operation issue docs, and package/theme drafts.
- **Priority:** Now / Dynamic language readiness.

### S2-023 Markdown embed iframe title used literal user-facing copy

- **Area:** Markdown rendering, accessibility copy, translation coverage.
- **Finding:** YouTube no-cookie embeds generated by the Markdown `design` profile used a hardcoded `title="Embedded video"` iframe title in PHP.
- **Evidence:** `src/View/MarkdownEmbedAdapter.php:40`, `src/View/MarkdownRenderer.php:153`, `tests/View/MarkdownRendererTest.php:55`.
- **Impact:** The rendered title is user-facing assistive copy. Leaving it as a PHP literal conflicts with the project rule that user-facing strings should use deterministic translation keys wherever practical.
- **Recommendation:** Pass a translated title from `MarkdownRenderer` into the embed adapter. Keep direct non-container renderer usage deterministic by falling back to the translation key rather than an English literal.
- **Fix applied:** Added `ui.markdown.embed.video_title` to UI translation sources, injected the translator-backed title into `MarkdownEmbedAdapter`, escaped the title attribute, and added a focused renderer regression.
- **Priority:** Now / Translation readiness.

### S2-024 Unsupported template namespaces used literal exceptions

- **Area:** Template namespace resolution and View extension boundary.
- **Finding:** `TemplateNamespace::fromName()` rejected unknown namespace strings with a literal `InvalidArgumentException`.
- **Evidence:** `src/View/Template/TemplateNamespace.php:21`, `src/View/Template/PackageTemplatePathResolver.php:26`, `tests/View/Template/PackageTemplatePathResolverTest.php:85`.
- **Impact:** The hard invariant is appropriate because only `frontend`, `backend`, and `root` are supported, but the failure is part of the View extension/configuration boundary and should expose a stable Message key for diagnostics.
- **Recommendation:** Preserve `InvalidArgumentException` compatibility through `MessageException` while moving the reason to a View-owned Message code/key.
- **Fix applied:** Added `view.template_namespace.unsupported` / `message.view.template_namespace.unsupported`, converted the throw to `MessageException`, updated translations, operation issue docs, and the resolver regression.
- **Priority:** Now / Message consistency.

### S2-025 Internal log channels, service tags, and setup session key used product-brand naming

- **Area:** Monolog channels/files, service-container tags, setup wizard session storage, admin log source browsing.
- **Finding:** Several internal technical identifiers still used `studio_*`/`studio.*` naming after earlier naming cleanup: Monolog channels and generated log filenames, event/backend view service tags, and the setup wizard session key.
- **Evidence:** `config/packages/monolog.yaml:4`, `config/services.yaml:22`, `config/services.yaml:25`, `bin/setup:188`, `src/Core/Log/LogSourceRegistry.php:14`, `src/Setup/SetupWizardState.php:9`.
- **Impact:** Behavior was not unsafe, but it contradicted the binding naming rule that `studio` is product branding or public UI/API, while system-owned internals should use the `system` owner/scope. It also created a review trap because adjacent tags had already moved to `system.*`.
- **Recommendation:** Rename internal channels, generated log filenames, service tags, and session keys to `system_*`/`system.*`. Leave product-facing Twig helpers, CLI command names, CSS classes, and installation-chosen DB prefixes untouched.
- **Fix applied:** Renamed Monolog channels/files to `system_message`, `system_audit`, and `system_access`; updated the manual setup-script logger, service tag names to `system.event_hook_provider` and `system.backend_view_provider`; changed the setup wizard session key to `_system_setup_wizard`; updated log source patterns, docs, and tests.
- **Priority:** Now / Final naming gate.

### S2-026 Package asset contribution invariants used literal exceptions

- **Area:** Package asset registry contributions and package extension boundary.
- **Finding:** `PackageAssetContribution` rejected empty package identifiers, unsupported contribution types, non-relative paths, and traversal paths with literal `InvalidArgumentException` messages.
- **Evidence:** `src/Core/Package/PackageAssetContribution.php:23`, `src/Core/Package/PackageAssetContribution.php:27`, `src/Core/Package/PackageAssetContribution.php:76`, `src/Core/Package/PackageAssetContribution.php:81`.
- **Impact:** These invariants are appropriate, but asset contributions are a documented package extension surface. Package author faults and loader diagnostics should carry stable Package Message keys rather than free text.
- **Recommendation:** Preserve the `InvalidArgumentException` contract through `MessageException` and add package-owned Message codes/keys for each asset-contribution invariant.
- **Fix applied:** Added package asset contribution Message codes/keys, converted the DTO to `MessageException`, updated translations and operation issue docs, and added focused regression coverage.
- **Priority:** Now / Package extension diagnostics.

### S2-027 Package admin detail provider mixed read-model assembly with file/link parsing

- **Area:** Backend package detail read model and admin package diagnostics.
- **Finding:** `PackageAdminDetailProvider` was still 370 lines and owned package detail assembly, manifest/README/preview file reads, image MIME/data URI handling, external URL sanitization, GitHub source-channel link construction, and dependency label parsing in one class.
- **Evidence:** `src/Backend/PackageAdminDetailProvider.php`, `templates/backend/admin/packages/detail.html.twig:30`, `tests/Controller/BackendControllerTest.php`.
- **Impact:** The behavior was not unsafe because paths already passed through `PathGuard`, previews were size/MIME bounded, and URLs were scheme/host checked. The class still violated the modularity goal and made future package-detail changes more context-expensive than needed.
- **Recommendation:** Keep `PackageAdminDetailProvider` as the view-model assembler and move IO/link/dependency helpers into focused backend services.
- **Fix applied:** Added `PackageAdminFileReader`, `PackageAdminLinkResolver`, and `PackageDependencyLabelParser`; reduced `PackageAdminDetailProvider` to 175 lines; added focused unit tests for link and dependency parsing; updated the class map.
- **Priority:** Now / Modularity.

### S2-028 Account token lookup and password error mapping were duplicated in controllers

- **Area:** Public account links, password reset, password change, registration, and invitation acceptance.
- **Finding:** `UserRegistrationController`, `UserPasswordRecoveryController`, and `UserController` repeated password-policy-to-error-key mapping, and the public account-link controllers duplicated pending-token lookup including token hashing, status filtering, and expiry checks.
- **Evidence:** `src/Controller/UserController.php`, `src/Controller/UserPasswordRecoveryController.php`, `src/Controller/UserRegistrationController.php`, `src/Security/AccountTokenIssuer.php`, `src/Security/PasswordPolicy.php`.
- **Impact:** The behavior was covered, but future Security work such as remember-me, token reuse detection, step-up prompts, or renamed password-policy keys would have to find and update multiple controller-private copies. That increases drift risk in exactly the area where we want one predictable policy boundary.
- **Recommendation:** Keep controllers responsible for request/response flow and move reusable token lookup and password policy presentation to Security-owned collaborators.
- **Fix applied:** Added `AccountTokenLookup` for pending, non-expired token resolution, added `PasswordPolicyErrorMapper` for stable user-facing password error keys, rewired the account controllers to use both helpers, added focused mapper coverage, and updated the class map.
- **Priority:** Now / Security readiness.

### S2-029 Core primitive hard exceptions and config defaults are deliberate

- **Area:** Core access, ActionLog, Config, Diff, DryRun, Message, and Workflow primitives.
- **Finding:** This slice still contains literal `InvalidArgumentException` messages in value objects and immutable result models. Unlike recoverable controller/setup/package boundaries, these classes enforce low-level programming invariants such as non-empty labels, typed entries, valid message codes/keys, terminal statuses, and review prompts.
- **Evidence:** `src/Core/ActionLog`, `src/Core/Diff`, `src/Core/DryRun`, `src/Core/Message`, `src/Core/Workflow`, `src/Core/Config/Config.php`, `src/Core/Config/Settings/CoreConfigDefaultProvider.php`, `tests/Core/Message/MessageCodeTest.php`, `tests/Core/Message/MessageKeyTest.php`, `tests/Core/Config/ConfigTest.php`.
- **Impact:** No user-facing behavior gap was found. Config read/write/storage failures report through the Message layer and fall back to registered defaults when possible. Message code/key tests enforce domain catalogue scope and translation synchronization.
- **Recommendation:** Keep these hard exceptions as deliberate primitive invariants. Continue moving recoverable runtime boundaries to `Message`/`WorkflowResult`, but do not wrap every immutable DTO assertion in translated diagnostics.
- **Fix applied:** None needed; audit decision recorded.
- **Priority:** Reviewed / No immediate change.

### S2-030 Live operation storage only trimmed POSIX project separators

- **Area:** Live-operation file storage and cross-platform path construction.
- **Finding:** `LiveOperationRunStorage::directory()` trimmed trailing `/` from the project directory but not trailing `\`. Most mixed-separator paths still work on Windows, but an externally constructed project directory ending in `\` could produce noisier `...\/var/...` paths than the rest of the process/storage code.
- **Evidence:** `src/Core/Operation/Live/LiveOperationRunStorage.php`, adjacent `src/Core/Messenger/DeferredMessengerDrain.php` and `src/Core/Process/PhpCliBinaryPreferenceStore.php` already trim both `/` and `\`.
- **Impact:** Low-risk portability drift rather than a known behavior break. Still worth aligning before Windows users exercise live operations more heavily.
- **Recommendation:** Normalize both common directory separators at the live-operation storage root and keep public path behavior covered.
- **Fix applied:** Changed the storage directory normalization to `rtrim($projectDir, '/\\')` and added a regression through `LiveOperationRunStore::outputPath()`.
- **Priority:** Now / Platform polish.

### S2-031 Access statistic trace identifiers accepted free payloads

- **Area:** Access statistics entity boundary, future rate limiting, and audit-log filtering.
- **Finding:** `AccessStatisticEvent` truncated `request_id` and stored `visitor_id` without validating either value as a compact technical token. The normal recorder path already supplies generated request IDs and HMAC-derived visitor IDs, but the entity boundary still allowed whitespace, control characters, or oversized/free-form payloads into indexed fields that future security tooling will query.
- **Evidence:** `src/Entity/AccessStatisticEvent.php`, `src/Core/Log/AccessRequestMetadata.php`, `src/Core/Statistics/VisitorIdGenerator.php`, `tests/Entity/AccessStatisticEventTest.php`.
- **Impact:** Low risk for current first-party writes, but poor hardening for future import/test/admin paths and a likely review edge because these IDs are intended to become rate-limit/audit-friendly technical handles.
- **Recommendation:** Validate stored request and visitor IDs centrally at the entity boundary as compact URL/log-safe trace tokens, surface invalid values through Statistics Message keys, and keep aggregation readers tolerant of already persisted rows.
- **Fix applied:** Added a statistics trace-ID validation key, translations, entity validation, operation-issue catalogue documentation, class-map note, and an entity regression test.
- **Priority:** Now / Security and observability readiness.

### S2-032 State marker metadata validation used a Content message key

- **Area:** Entity validation, Message catalogue ownership, and reusable state markers.
- **Finding:** `StateMarker` correctly validated subject type and marker keys through State-owned Message keys, but empty metadata keys reused `ContentMessageKey::CONTENT_METADATA_KEY_EMPTY`. That made a reusable Core state primitive depend on Content catalogue naming for a non-content invariant.
- **Evidence:** `src/Entity/StateMarker.php`, `src/Core/State/StateMessageKey.php`, `tests/Entity/CoreDatabaseModelTest.php`.
- **Impact:** Functional behavior was acceptable, but it violated the domain-owned Message catalogue rule and would make future docs or package-facing diagnostics harder to explain.
- **Recommendation:** Keep the validation, but move the key to the State catalogue and document it alongside other state marker diagnostics.
- **Fix applied:** Added `message.state.metadata.key_empty`, translations, issue-catalog documentation, class-map note, and an entity regression test.
- **Priority:** Now / Message catalogue consistency.

### S2-033 Site menu item targets lacked an entity-level boundary

- **Area:** Navigation entity validation, public menu data, and target naming.
- **Finding:** Navigation URL resolution safely falls back for unsupported target types and unsafe URLs, but `SiteMenuItem` accepted arbitrary `targetType` and `targetValue` values. That left persisted menu data more permissive than the public navigation contract.
- **Evidence:** `src/Entity/SiteMenuItem.php`, `src/Navigation/NavigationUrlResolver.php`, `src/Navigation/NavigationItem.php`, `tests/Entity/CoreDatabaseModelTest.php`.
- **Impact:** Runtime rendering was defensive, so this was not an immediate public exploit path. The looser entity boundary still made malformed admin/seed/import data possible and kept target type naming as repeated magic strings.
- **Recommendation:** Centralize supported navigation target type names and reject unknown, empty, control-character, or oversized targets before persistence.
- **Fix applied:** Added `NavigationTargetType`, reused it in the entity/DTO/resolver, added Navigation Message keys/translations, documented the keys, and covered invalid targets in entity tests.
- **Priority:** Now / Entity boundary and naming consistency.

### S2-034 Observability storage and diagnostics needed small determinism polish

- **Area:** Statistics snapshot storage, system diagnostics, and class-size cleanup.
- **Finding:** The statistics store already used unique temp files but still normalized its root with POSIX-only trimming. System diagnostics exposed a reduced extension list, but the order came directly from `get_loaded_extensions()`. `AccessStatisticsAggregator` also had an unused boolean row helper that kept the class at the 300-line threshold.
- **Evidence:** `src/Core/Statistics/FileAccessStatisticsStore.php`, `src/Core/Diagnostics/SystemInfoProvider.php`, `src/Core/Statistics/AccessStatisticsAggregator.php`, `tests/Core/Statistics/FileAccessStatisticsStoreTest.php`.
- **Impact:** No known behavior break, but Windows-style roots, non-deterministic admin output, and dead code are exactly the kind of small drift review tends to surface late.
- **Recommendation:** Normalize both common separators, sort extension names case-insensitively before rendering, and remove unused helpers.
- **Fix applied:** Updated statistics store path normalization, added a trailing-backslash regression, sorted loaded extensions, and removed the unused aggregator helper.
- **Priority:** Now / Platform and readability polish.

### S2-035 File inventory roots only trimmed the current platform separator

- **Area:** Reusable filesystem inventory scanning and cross-platform path normalization.
- **Finding:** `FileInventoryScanner` trimmed only `DIRECTORY_SEPARATOR` from its root before calculating relative paths. A mixed-separator root produced by tests, tooling, or imported configuration could therefore be treated as missing on POSIX or leave avoidable separator drift in relative path calculation.
- **Evidence:** `src/Core/Filesystem/FileInventoryScanner.php`, `tests/Core/Filesystem/FileInventoryScannerTest.php`, adjacent `PathGuard` and process/live-operation storage helpers already normalize both `/` and `\`.
- **Impact:** Low-risk portability drift, but this scanner is reusable for package/import/export/debug inventory paths, so it should follow the same separator policy as the rest of Core filesystem code.
- **Recommendation:** Normalize both common separators before existence checks while preserving filesystem roots such as `/` and drive roots, then keep emitted inventory paths POSIX-style.
- **Fix applied:** Added a root normalizer, a trailing-backslash regression test, and renamed the internal test temp prefix from `studio-*` to `system-*`.
- **Priority:** Now / Platform polish.

### S2-036 Translation support paths needed deterministic separator and ordering polish

- **Area:** Runtime translation aggregation, source hashing, and generated catalogue discovery.
- **Finding:** Translation source collection and runtime path logic already separated source catalogues from generated Symfony catalogues, but `relativeSourcePath()` trimmed only the current platform separator from `projectDir`, and generated catalogue discovery returned `glob()` output directly. Test temp naming also still used an internal `studio-*` prefix.
- **Evidence:** `src/Core/Translation/TranslationSourceCollector.php`, `src/Core/Translation/TranslationRuntimePath.php`, `tests/Core/TranslationCatalogueAggregatorTest.php`.
- **Impact:** Runtime behavior was already correct for normal project paths, but source hashes and generated catalogue lists should be deterministic and cross-platform wherever possible.
- **Recommendation:** Normalize project-root separator trimming across both common separators, sort generated catalogue paths explicitly, and keep internal temp/test naming under `system`.
- **Fix applied:** Updated `relativeSourcePath()`, sorted generated catalogue paths, and renamed the translation aggregation test temp prefix to `system-*`.
- **Priority:** Now / Determinism and naming consistency.

### S2-037 Custom content Twig failures were silent fallback-only events

- **Area:** Content rendering, schema custom Twig, Message diagnostics.
- **Finding:** `ContentFieldsetRenderer` intentionally falls back to the generic field renderer when schema `custom_twig` cannot be rendered, but the failure was not reported anywhere. That keeps public content available, which is good, but leaves admins/operators without an actionable diagnostic for a broken trusted schema template.
- **Evidence:** `src/Content/Render/ContentFieldsetRenderer.php`, `templates/frontend/content/partials/_generic-fields.html.twig`, `dev/draft/0.3.x-SchemaContentFields.md`.
- **Impact:** A stale or invalid schema template could quietly degrade rendering until someone visually notices the fallback output. Because custom Twig is a trusted-admin feature, this should stay non-fatal for public traffic but visible through the Message/operation issue layer.
- **Recommendation:** Keep the fallback behavior, inject the Message reporter optionally, and emit a content-owned warning with bounded schema/content context when custom Twig rendering fails.
- **Fix applied:** Added `content.render.custom_twig_failed` / `message.content.render.custom_twig_failed`, reported invalid custom Twig before falling back, documented the diagnostic, and added focused renderer coverage.
- **Priority:** Now / Operability and Message-layer consistency.

### S2-038 Account-link debug delivery duplicated clear tokens in log context

- **Area:** Account-token delivery, mailer stub, Security/Logging.
- **Finding:** `MessageLogAccountLinkDelivery` must keep action URLs visible until real mail delivery exists, but it also logged the same clear one-time token as a separate `debug_plain_token` context field through `MailDeliveryMessage`.
- **Evidence:** `src/Security/MessageLogAccountLinkDelivery.php`, `src/Mail/MailDeliveryMessage.php`, `tests/Security/MessageLogAccountLinkDeliveryTest.php`, `dev/draft/0.4.x-MailerDeliveryContract.md`.
- **Impact:** The action URL is currently the only retrievable local delivery path, but duplicating the raw token in a second context field increases leak surface and makes future production gating easier to miss.
- **Recommendation:** Keep action-url based local delivery until Symfony Mailer exists, remove the plain-token field from the mail delivery contract and logs, and document that production must replace or debug-gate the stub before release.
- **Fix applied:** Removed `debugPlainToken` from `MailDeliveryMessage`, removed the plain-token parameter from `AccountLinkDeliveryInterface::deliver()`, updated all account-flow callers, and adjusted tests/docs to assert no separate plain-token context field is logged.
- **Priority:** Now / Security hardening.

### S2-039 CLI setup input still mixed database-selection details into the top-level factory

- **Area:** Setup CLI input, modularity, context-size target.
- **Finding:** `SetupCliInputFactory` had dropped below the highest setup-risk classes after earlier setup refactors, but it still remained above the 300-line target and owned database driver availability, explicit URL/driver mismatch handling, interactive database prompts, server database defaults, SQLite URL defaults, and prefix normalization alongside the high-level setup input assembly.
- **Evidence:** `src/Setup/SetupCliInputFactory.php`, `tests/Setup/SetupCliInputFactoryTest.php`, `dev/CLASSMAP.md`.
- **Impact:** The behavior was covered, but the class was harder to audit because setup orchestration and database-choice policy lived together. The SQLite default also needed explicit regression coverage to preserve the selected/environment `APP_ENV` after extracting the logic.
- **Recommendation:** Move CLI database input resolution into a focused collaborator and keep `SetupCliInputFactory` as the setup input orchestrator.
- **Fix applied:** Added `SetupCliDatabaseInput`, reduced `SetupCliInputFactory` from 338 to 152 lines, added a regression test for environment-specific SQLite defaults, and kept the existing CLI setup behavior covered by the focused setup suite.
- **Priority:** Now / Modularity.

### S2-040 Setup wizard template mixed all step rendering into one file

- **Area:** Setup wizard templates, backend partial structure, context-size target.
- **Finding:** `templates/backend/setup/index.html.twig` still rendered alerts, preflight details, every setup step, footer navigation, and result logs in one 373-line template. The file was not a PHP class, but it had become a volatile UI orchestrator where small step changes required loading the whole wizard.
- **Evidence:** `templates/backend/setup/index.html.twig`, `templates/backend/setup/partials/_step-header.html.twig`, `dev/CLASSMAP.md`.
- **Impact:** Runtime behavior was functional, but the structure worked against the modularity and LLM-context rules. Future setup UI changes, preflight-row adjustments, or result-log tweaks would have a larger review surface than necessary.
- **Recommendation:** Keep the setup index as the form/frame orchestrator and move alerts, preflight rows, footer navigation, result logs, and step-specific panels into backend setup partials.
- **Fix applied:** Split setup rendering into focused partial templates under `templates/backend/setup/partials/**`, reduced the index template from 373 to 95 lines, rendered `/setup`, and ran focused setup/backend tests.
- **Priority:** Now / Modularity and review readability.

## Cross-Cutting Passes

- Fresh file and large-file inventory captured.
- `studio` technical naming scan started; raw results include intentional branding/UI CSS/command/log-channel uses and require classification.
- Direct hard-exception scan rerun with fixed-string searches. Most remaining hard exceptions are low-level value-object, filesystem, lint, checksum, or manifest invariants; setup input validation remains the first likely Message-layer gap.
- Setup failure scan rechecked after S2-002: old free setup input/failure texts were removed from primary throw paths. `LiveOperationStarter` still uses local runtime exceptions for unrecoverable process-start adapter failures that are immediately caught and reported as `operation.start_failed`, so it is currently treated as deliberate adapter behavior.
- Large production class review started with account-flow controllers, package lifecycle activation/removal/registry/install, runtime loader, and package scheduler cron inspection. Account-flow controllers are the first remaining controller-as-adapter gap; package scheduler cron validation is large but conservative and currently blocks dynamic cron bypasses.
- Hardcoded language scan reviewed. Remaining `en`/`de` references are currently content seed variants, test fixtures, default bootstrap examples, or code-editor language identifiers except for S2-005 and S2-022.
- Package policy bypass scan reviewed. Direct file/process/env/network calls, include/require/eval, reserved package paths, source namespaces, translation namespaces, symlink zip entries, scheduler cron literals, and source paths are validated; S2-006 closes the dynamic callable gap.
- Session visitor binding reviewed. Hard binding works for missing/different visitor cookies, but complete cookie-pair duplication remains a deferred risk-scoring problem rather than a safe hard-termination signal today.
- User profile language persistence reviewed. S2-009 now validates posted profile language values against the dynamic locale list instead of relying on later resolver fallback.
- Package runtime contribution registry reviewed. S2-010 now exposes unsupported contribution and package scheduler privilege failures through Message-layer diagnostics instead of literal exception text.
- Public event hook registry reviewed. S2-011 now prevents later descriptor providers from silently overriding earlier public hook metadata.
- Scheduler task definitions reviewed. S2-012 now uses Scheduler Message keys for public extension-point invariant failures.
- Scheduler controller/route usage reviewed. `/cron/run` auth, direct job forcing, invalid job handling, and Admin Scheduler route generation match the scheduler draft.
- Mail locale resolution reviewed. S2-013 records deferred Mail Message/API hardening; current locale behavior matches the documented recipient-first policy.
- Navigation labels reviewed. S2-014 now handles regional locale tokens through normalized/primary-language fallback.
- Content read language behavior reviewed. S2-015 aligns regional locale fallback and persisted content field token validation with the dynamic language catalogue.
- UID strategy reviewed. `UuidFactory` centrally uses Symfony `Uuid::v7()`, while entities store RFC 4122 strings in portable `VARCHAR(36)` columns. This remains an intentional platform-compatibility tradeoff; binary UUID storage can be reconsidered only if a future DB abstraction makes it portable.
- Visitor/request identity reviewed. Visitor IDs are 22-character HMAC-derived IDs backed by a signed 30-day first-party cookie; request IDs are 24 hex chars. No hard IP/UA binding was added in this pass.
- Statistics snapshot storage reviewed. S2-016 now avoids fixed temp-file collisions.
- Core filesystem actions reviewed. Relative path, absolute path, traversal, target symlink, and parent symlink checks are centralized through `PathGuard` and WorkflowResults; no immediate policy bypass found.
- Live-operation start failure handling reviewed. S2-017 now uses operation-owned Message keys for runner-start and PHP-CLI-unavailable failures.
- Remember-me login noted for Security work. S2-018 captures the preferred Symfony-native, server-side persistent-token design with visitor-cookie binding and rotation.
- Core translation aggregation reviewed. Runtime writer failures remain low-level atomic adapter exceptions, but the aggregator maps them to `message.translation.aggregate_failed`; package catalogue collisions are deliberately rejected instead of silently overridden.
- Core lint/manifest/message value-object invariants reviewed. Literal exceptions there are low-level construction invariants, not recoverable user workflow failures. S2-019 fixes the one internal product-brand temp prefix found in linting.
- Database prefixing reviewed. `PrefixedConnection`, `DoctrineTablePrefixListener`, setup/migration support, and table inventory tests cover known application tables; the `APP_DATABASE_PREFIX` value itself is intentionally user-facing and may be product-branded by an installation.
- Runtime config defaults reviewed. `Config::get()` already falls back to registered core setting defaults when the DB is unavailable, a key is missing, reads fail, or stored JSON is invalid; callers only see their explicit default after no registered seed/default exists.
- Security tokens reviewed. Account links/recovery tokens are server-side rows storing only SHA-256 token hashes, while API keys use APP_SECRET-rooted HMAC plus encrypted reversible payloads; remember-me should be a separate credential model rather than reusing account-link tokens.
- Account-link debug delivery reviewed. S2-038 removes separate plain-token log context while keeping action-url based local delivery until the Mailer slice can replace or debug-gate the stub.
- Maintenance mode reviewed. The public UI uses translated 503 error-page keys; the literal `ServiceUnavailableHttpException` text is debug-only HTTP control-flow and acceptable as a Symfony-native boundary.
- APP_SECRET rotation reviewed. S2-020 records the remaining idempotency edge around repeated owner recovery delivery if fingerprint persistence fails.
- Debug/view internal naming reviewed. S2-021 moves internal debug collector/comment and backend form request attributes from `studio` to `system`; `studio_*` Twig helpers and CSS classes remain intentionally public/product-facing.
- Package translation fallback policy reviewed. S2-022 keeps package fallback validation deterministic while replacing the hardcoded `languages/en` requirement with configured fallback-locale candidates.
- Markdown embed accessibility copy reviewed. S2-023 moves the iframe title to `ui.markdown.embed.video_title` and keeps standalone rendering key-based.
- Template namespace resolution reviewed. S2-024 keeps the invariant hard but exposes unsupported namespace failures through View Message keys.
- View/Twig runtime reviewed. Response header hooks keep sensitive header mutations blocked, output hooks are HTML-only, dynamic injection rendering failures already report through View Message diagnostics, and public `studio_*` Twig helper names remain intentional theme API.
- Internal logging/service naming reviewed. S2-025 moves Monolog channel/file names, event/backend view tags, and setup wizard session storage to `system` naming while classifying `studio_*` Twig helpers and CSS classes as public product/theme API.
- Package asset contribution invariants reviewed. S2-026 moves package author-facing asset contribution failures to Package Message keys.
- Package admin detail read model reviewed. S2-027 splits file IO, URL sanitization, and dependency label parsing out of the oversized provider.
- Account token/password flows reviewed. S2-028 centralizes pending-token lookup and password-policy UI error mapping outside controllers while leaving the larger account-flow service extraction tracked by S2-003.
- Core primitive foundations reviewed. S2-029 records that hard exceptions in ActionLog/Diff/DryRun/Message/Workflow are deliberate low-level invariants, while Config runtime failures already use Message diagnostics and central defaults.
- Live-operation storage reviewed. S2-030 aligns project-root trimming with the rest of the cross-platform process/file storage code.
- Access statistic entity boundaries reviewed. S2-031 validates request/visitor trace identifiers as compact technical tokens before new rows are created.
- State marker entity validation reviewed. S2-032 moves reusable state metadata validation to State-owned Message keys instead of Content keys.
- Navigation entity boundaries reviewed. S2-033 centralizes target type names and validates menu targets before persistence.
- Observability diagnostics reviewed. S2-034 normalizes statistics-store roots, sorts extension diagnostics, and removes an unused aggregator helper.
- Core operation filesystem inventory reviewed. S2-035 normalizes scanner roots across separators while preserving root paths.
- Core support translation paths reviewed. S2-036 stabilizes generated catalogue order and mixed-separator project root handling.
- Content custom-Twig rendering reviewed. S2-037 keeps public fallback behavior but reports broken schema templates through content-owned Message diagnostics.
- Admin system-info page reviewed. It exposes reduced, admin-panel-only preflight/server/PHP capability data and avoids raw `$_SERVER`/full `phpinfo()` output.
- Command names reviewed. `studio:*` remains intentional product CLI branding, unlike internal technical service tags that moved to `system.*`.
- Process environment reviewed. `CliProcessEnvironment::fromCurrentProcess()` keeps Symfony Dotenv/app values and removes web/CGI request context; process-starting callers use that boundary.
- Setup PHP-CLI and Composer preflight reviewed. Cached `APP_DEFAULT_PHP_BINARY` remains validation-first and auto-heal/persistence is limited to controlled setup/preflight flows.
- Setup CLI input reviewed. S2-039 extracts database input resolution from the CLI input factory and leaves all setup production classes below the 300-line target.
- Setup wizard templates reviewed. S2-040 moves step-specific rendering into setup partials while preserving the existing controller context, translation keys, form fields, and routes.
- Form builder/submission layer reviewed. No immediate drift found: values cast centrally, option validation is generic, and user-facing errors stay on existing translation keys.

## Fixes Applied

- Renamed internal system-owned technical identifiers away from `studio` to `system` for service tags, scheduler locks, secret fingerprint labels, DBAL connection wrapper parameters, and Messenger/Scheduler marker files.
- Converted setup input validation and setup-step failure boundaries from free-text hard exceptions to domain-owned Message-layer keys while keeping hard invariant behavior.
- Removed the hardcoded root-template English fallback by surfacing the resolved default locale through the view context.
- Hardened installable package PHP validation against dynamic callable and reflection bypasses.
- Rejected unsupported crafted profile language submissions before they reach account settings.
- Converted package runtime contribution and package scheduler privilege failures to structured Package Message keys and retained those keys in PHP-loader fault context.
- Made public event hook descriptor aggregation first-wins so future package providers cannot silently override system hook metadata.
- Converted Scheduler task definition invariant failures to structured Scheduler Message keys while preserving InvalidArgument-compatible behavior.
- Added normalized and primary-language fallback for persisted navigation labels.
- Added regional-locale fallback for content reads and shared locale-token validation for content field values.
- Made access statistics snapshot writes use unique temp files with cleanup on failed rename.
- Converted live-operation runner-start and PHP-CLI-unavailable startup failures to operation-owned Message keys.
- Renamed the PHP linter internal temp-file prefix from `studio-*` to `system-*`.
- Replaced the package translation `languages/en` special case with a configured fallback-locale validation rule and neutral Message code/key.
- Moved Markdown embed iframe title copy from PHP literal to translated UI keys.
- Converted unsupported template namespace failures from literal exceptions to View Message keys.
- Renamed internal log channels/files, service tags, and setup wizard session key from `studio` to `system` naming.
- Converted package asset contribution invariant failures from literal exceptions to Package Message keys.
- Split package admin detail helper responsibilities into focused backend services.
- Extracted repeated account token lookup and password-policy error mapping into Security helpers.
- Removed separate clear-token context logging from the account-link message-log delivery stub and narrowed the delivery contract to generated action URLs.
- Split CLI setup database input resolution out of the top-level CLI input factory.
- Split the web setup wizard render target into focused backend setup partials.
- Normalized trailing POSIX and Windows separators for live-operation storage paths.
- Validated access-statistics request and visitor trace identifiers before persistence.
- Moved state-marker metadata validation to the State Message catalogue.
- Added entity-level validation for persisted site menu item targets.
- Normalized statistics snapshot storage roots and made system extension diagnostics deterministic.
- Normalized file-inventory scanner roots across POSIX and Windows separators.
- Stabilized translation source/runtime path ordering and internal test naming.
- Reported broken content schema custom Twig through the Message layer before falling back to the generic field renderer.
