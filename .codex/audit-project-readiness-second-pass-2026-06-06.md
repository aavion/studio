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
| Backend | `src/Backend` | In progress | Admin settings/system-info path and backend view context reviewed. System-info uses a reduced admin-only report, not raw `phpinfo()` or `$_SERVER`; S2-021 renames internal backend form request attributes to `system`. Package detail provider size/read-model split still needs final assessment. |
| Command | `src/Command` | Reviewed | Commands are small and use `studio:` as intentional product CLI branding. Process-heavy work delegates into services; no immediate command naming drift found. |
| Content | `src/Content` | In progress | Content read resolution, routing language behavior, and content field locale tokens reviewed. S2-015 hardens regional locale fallback and persisted field locale compatibility. Aggregate/API read-model boundaries still need review. |
| Controller | `src/Controller` | In progress | Account registration/invitation/profile/password flows reviewed first; S2-003 records the remaining controller-as-adapter gap and S2-009 hardens profile language persistence. Setup/backend leftovers still need review. |
| Core primitives | `src/Core/Access`, `ActionLog`, `Config`, `Diff`, `DryRun`, `Message`, `Workflow` | Pending | Re-check config default fallbacks, message catalogues, hard throws, and public naming. |
| Core operations | `src/Core/Filesystem`, `Operation`, `Process`, `Messenger` | In progress | Process environment, detached process boundaries, filesystem actions, and live-operation start failure handling reviewed. Dotenv app values are passed to child processes while web/CGI context is filtered. Filesystem symlink guards use WorkflowResults; S2-017 converts live-operation start failure reasons to Message-layer diagnostics. Messenger still needs final sweep. |
| Core package | `src/Core/Package` | In progress | `PackageActivator`, `PackageRemover`, registry sync, fault reset, runtime loader, package install apply, scheduler cron validation, PHP capability policy, runtime contribution registry, and asset registry contributions reviewed. S2-004 keeps cron parser behavior, S2-006 hardens dynamic callable bypasses, S2-007 records the remaining lifecycle transaction boundary, S2-010 converts package runtime contribution failures to Message-layer diagnostics, and S2-026 converts asset contribution invariants to Package Message keys. |
| Core observability | `src/Core/Log`, `Statistics`, `Diagnostics` | In progress | Visitor/request ID, access metadata sanitization, statistics recorder/aggregator/store reviewed. S2-016 hardens snapshot temp-file writes. S2-025 renames internal log channels/files to `system_*`; public CSS/UI names remain product-facing. Diagnostics/debug naming still needs review. |
| Core support | `src/Core/Translation`, `Lint`, `Manifest`, `Event`, selected support helpers | In progress | Event hook registry reviewed; S2-011 prevents silent public hook descriptor overrides. Translation/runtime paths, catalogue collision handling, lint, manifest, and Message invariants reviewed. S2-019 renames an internal lint temp prefix to `system-*`. Remaining pass: package catalogue conflict docs/tests and broader generated catalogue checks. |
| Database | `src/Database` | Reviewed | Table-prefix coverage, raw DBAL wrapper prefixing, Doctrine metadata prefixing, and migration portability reviewed. `studio_` remains a user-facing/product example prefix, while internal DBAL wrapper params use `system_*`. |
| Debug and Kernel | `src/Debug`, `src/Kernel.php` | Reviewed | Debug collector naming, output safety, and APP_DEBUG gating reviewed. S2-021 renames the internal collector and debug HTML comment to `system`; public Twig helper names remain `studio_*` as theme-facing API. |
| Entity and Repository | `src/Entity`, `src/Repository` | In progress | Entity inventory, UID storage, statistics indexes, and content field locale token compatibility reviewed. UUIDv7 RFC 4122 strings remain the portable pre-1.0 tradeoff; repositories/filtering boundaries still need final assessment. |
| Form, Mail, Navigation, Localization | `src/Form`, `src/Mail`, `src/Navigation`, `src/Localization` | Reviewed | Locale resolver, form builder/submission layer, mail locale behavior, and navigation label fallback reviewed. S2-013 records the deferred Mail Message/API hardening; S2-014 hardens navigation primary-language fallback. |
| Scheduler | `src/Scheduler` | In progress | Scheduler task registry, lock naming, package task policy, run recorder, web-auth settings, and task definitions reviewed. S2-012 converts public task definition invariants to Message-layer diagnostics. Remaining pass: route/controller usage and docs alignment. |
| Security | `src/Security` | In progress | Session visitor binding, AccountToken issuer/entity behavior, API-key vault/entity behavior, maintenance-mode HTTP flow, and remember-me direction reviewed. S2-008 records the remaining copied-session plus copied-visitor-cookie limitation, and S2-018 captures remember-me as a Security-branch feature candidate using server-side rotating tokens bound to the visitor cookie. ACL groups, account-flow controller extraction, and secret rotation still need broader review. |
| Setup | `src/Setup` | In progress | PHP-CLI resolver/preference flow, dry-run placeholder behavior, preflight failure mapping, Composer probe, and setup subprocess environment reviewed. Large setup input/runtime classes remain watchlisted, but no immediate review-blocker found in this slice. |
| View | `src/View` | In progress | Template runtime fallback reviewed; S2-005 removes a hardcoded `en` fallback from the root layout. S2-023 moves Markdown embed accessibility copy to translations. S2-024 converts unsupported template namespace failures to View Message keys. Twig helper split, response header policy, dynamic injection failure ownership, and technical naming still need broader review. |
| Assets/Templates/Translations | `assets`, `templates`, `translations` | In progress | Hardcoded language variants and package translation fallback policy reviewed. S2-022 replaces the package `languages/en` special case with a configured fallback-locale requirement. S2-023 fixes Markdown embed UI copy. Remaining pass: broader CSS naming classification. |
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
- **Recommendation:** Defer implementation to the Security feature branch and keep the current normal session behavior unchanged here. Prefer Symfony's remember-me architecture with persistent server-side tokens: the browser stores only an opaque selector/token cookie, the server stores the hashed token plus user, expiry, visitor binding, and revocation state, the trust window is 7 days, automatic use rotates the token value without silently extending the original expiry, explicit credential login with the checkbox can issue a fresh 7-day token, successful auto-login creates a fresh Symfony session, token metadata binds to the current `system_visitor` cookie, manual logout/password/security events revoke the token, and visitor mismatch or token reuse is a hard reject plus audit signal. Keep normal session TTL low only after UX and admin workflows are reviewed; `60` minutes is a reasonable candidate but should be decided in Security.
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
- Maintenance mode reviewed. The public UI uses translated 503 error-page keys; the literal `ServiceUnavailableHttpException` text is debug-only HTTP control-flow and acceptable as a Symfony-native boundary.
- APP_SECRET rotation reviewed. S2-020 records the remaining idempotency edge around repeated owner recovery delivery if fingerprint persistence fails.
- Debug/view internal naming reviewed. S2-021 moves internal debug collector/comment and backend form request attributes from `studio` to `system`; `studio_*` Twig helpers and CSS classes remain intentionally public/product-facing.
- Package translation fallback policy reviewed. S2-022 keeps package fallback validation deterministic while replacing the hardcoded `languages/en` requirement with configured fallback-locale candidates.
- Markdown embed accessibility copy reviewed. S2-023 moves the iframe title to `ui.markdown.embed.video_title` and keeps standalone rendering key-based.
- Template namespace resolution reviewed. S2-024 keeps the invariant hard but exposes unsupported namespace failures through View Message keys.
- Internal logging/service naming reviewed. S2-025 moves Monolog channel/file names, event/backend view tags, and setup wizard session storage to `system` naming while classifying `studio_*` Twig helpers and CSS classes as public product/theme API.
- Package asset contribution invariants reviewed. S2-026 moves package author-facing asset contribution failures to Package Message keys.
- Admin system-info page reviewed. It exposes reduced, admin-panel-only preflight/server/PHP capability data and avoids raw `$_SERVER`/full `phpinfo()` output.
- Command names reviewed. `studio:*` remains intentional product CLI branding, unlike internal technical service tags that moved to `system.*`.
- Process environment reviewed. `CliProcessEnvironment::fromCurrentProcess()` keeps Symfony Dotenv/app values and removes web/CGI request context; process-starting callers use that boundary.
- Setup PHP-CLI and Composer preflight reviewed. Cached `APP_DEFAULT_PHP_BINARY` remains validation-first and auto-heal/persistence is limited to controlled setup/preflight flows.
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
