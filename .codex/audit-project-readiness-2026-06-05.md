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

## Coverage Log

### Production Inventory Baseline

- Production files under `src/`: `445`.
- Production PHP files under `src/`: `437`.
- Production PHP lines under `src/`: `49,812`.
- Production class/interface/trait/enum signature matches under `src/`: `449`.
- Production public method/static method/constant signature matches under `src/`: `2,368`.
- Public extension/entry-point signal matches under `src/`: `343`.
- Current confidence: all production domains reviewed, cross-cutting scans completed, and findings captured for follow-up planning.

### Domain Progress

| Domain | Scope | Status | Notes |
| --- | --- | --- | --- |
| Backend | `src/Backend` | Reviewed | Registry and access primitives are clear; package lifecycle admin view-model composition needs extraction. |
| Command | `src/Command` | Reviewed | Commands are mostly thin adapters; output/result rendering is duplicated and should be standardized. |
| Content | `src/Content` | Reviewed | Routing/read/value objects are clear; custom Twig trust policy and API-facing read models need decisions before Editor/API. |
| Controller | `src/Controller` | Reviewed | Multiple controllers still carry application workflow logic; user/token/setup/backend flows need service boundaries before Security/API. |
| Core primitives | `src/Core/Access`, `ActionLog`, `Config`, `Diff`, `DryRun`, `Message`, `Workflow` | Reviewed | Small primitives are mostly clear; global message constant catalogues are growing into a cross-domain registry. |
| Core operations | `src/Core/Filesystem`, `Operation`, `Process`, `Messenger` | Reviewed | Path/process helpers are mostly careful; live-operation persistence/locking remains the major split/Symfony-alignment candidate. |
| Core package | `src/Core/Package` | Reviewed | Archive/path safety is careful; lifecycle state changes, manifest syntax, and package runtime trust boundaries need refactoring/decisions before modules. |
| Core observability | `src/Core/Log`, `Statistics`, `Diagnostics` | Reviewed | Existing privacy redaction is useful; request/visitor identity, aggregation compaction, and log-browser modularity need follow-up before API/Security. |
| Core support | `src/Core/Translation`, `Lint`, `Manifest`, `Event`, selected support helpers | Reviewed | Most helpers are small and vendor-backed; translation aggregation and public-event wrapper policy need clearer boundaries. |
| Database | `src/Database` | Reviewed | Setup gating is small; table-prefix SQL rewriting and manual table inventory need hardening before schema growth. |
| Debug and Kernel | `src/Debug`, `src/Kernel.php` | Reviewed | Kernel is Symfony-standard; debug collector is debug-gated and bounded. |
| Entity and Repository | `src/Entity`, `src/Repository` | Reviewed | Repositories are intentionally thin; content entities and access-rule storage need stronger aggregate/value-object boundaries before API/Editor. |
| Form, Mail, Navigation, Localization | `src/Form`, `src/Mail`, `src/Navigation`, `src/Localization` | Reviewed | APIs are readable; generated forms need a clear boundary, navigation builder needs splitting, locale preference resolution should be centralized. |
| Scheduler | `src/Scheduler` | Reviewed | Definition/provider/executor APIs are readable; runner orchestration, locking, and child-process env policy need stronger Symfony-aligned boundaries. |
| Security | `src/Security` | Reviewed | Core primitives are small; ACL impact cleanup, mail-token delivery, secret rotation, and admin user read models need hardening before the dedicated Security/API work. |
| Setup | `src/Setup` | Reviewed | Runner/preflight are the largest boundaries; CLI/web input normalization, seeding, and setup payload crypto need clearer shared services. |
| View | `src/View` | Reviewed | Twig helpers, markdown, template paths, response hooks, and injection APIs are mostly careful; public hook policies and helper split remain open. |

### Review Method

1. Inspect every production PHP file by domain, including small value objects and enums.
2. Check public methods, interfaces, providers, command names, route entry points, and hook names for clear documentation-friendly naming.
3. Record refactoring candidates separately from immediately safe fixes.
4. Prefer Symfony-native/vendor-backed mechanisms where they reduce custom maintenance without hiding project-specific behavior.
5. Track security, privacy, platform, and performance edges even when they are deferred to API/Security/Editor work.

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

## Early Findings

### F-001 Package ZIP install orchestration is too large

- **Area:** Core package installation.
- **Finding:** `PackageZipInstaller` combines upload staging, ZIP extraction, package-root detection, manifest parsing/validation, package validation, dependency preflight, replacement/reactivation handling, filesystem copy/removal, and low-level ZIP/symlink safety.
- **Evidence:** `src/Core/Package/Install/PackageZipInstaller.php:32`, `src/Core/Package/Install/PackageZipInstaller.php:53`, `src/Core/Package/Install/PackageZipInstaller.php:111`, `src/Core/Package/Install/PackageZipInstaller.php:842`, `src/Core/Package/Install/PackageZipInstaller.php:977`, `src/Core/Package/Install/PackageZipInstaller.php:1009`.
- **Impact:** Maintainability and security review risk. The class is over 1100 lines, making it hard to reason about dangerous archive and filesystem boundaries.
- **Recommendation:** Split into `PackageUploadStager`, `PackageArchiveExtractor`, `PackageInstallVerifier`, `PackageInstallApplier`, and reusable filesystem/archive safety helpers. Keep the current public facade as a thin orchestrator until callers move.
- **Priority:** Before Admin/Editor.

### F-002 Live operation storage mixes persistence, presentation, locking, and process control

- **Area:** Core live operations.
- **Finding:** `LiveOperationRunStore` handles JSON persistence, report DTO shaping, stale-state mutation, runner locks, PID matching, process killing, cleanup, and message normalization.
- **Evidence:** `src/Core/Operation/Live/LiveOperationRunStore.php:17`, `src/Core/Operation/Live/LiveOperationRunStore.php:42`, `src/Core/Operation/Live/LiveOperationRunStore.php:114`, `src/Core/Operation/Live/LiveOperationRunStore.php:274`, `src/Core/Operation/Live/LiveOperationRunStore.php:350`, `src/Core/Operation/Live/LiveOperationRunStore.php:571`.
- **Impact:** Security and portability risk around locks/process handling, plus high context cost for future operation features.
- **Recommendation:** Split file persistence, lock management, runner supervision, report building, and cleanup into separate services. Evaluate `symfony/lock` for runner locks before adding more cross-process behavior.
- **Priority:** Before Security.

### F-003 Backend controller is still a feature hub

- **Area:** Admin and backend controllers.
- **Finding:** `BackendController` handles dynamic backend routing, package install/lifecycle, operations maintenance, log detail, statistics, system-info context, backend actions, settings forms, audit logging, live-operation JSON payloads, and navigation.
- **Evidence:** `src/Controller/BackendController.php:42`, `src/Controller/BackendController.php:71`, `src/Controller/BackendController.php:343`, `src/Controller/BackendController.php:458`, `src/Controller/BackendController.php:523`, `src/Controller/BackendController.php:662`.
- **Impact:** Admin features become harder to extend without touching one central controller. This also hides natural route/action ownership from docs and class maps.
- **Recommendation:** Keep dynamic backend routing as a small dispatcher and move package install/lifecycle, operation maintenance, log detail, settings post handling, and system-info context into focused controllers or action services.
- **Priority:** Before Admin/Editor.

### F-004 Setup runner and preflight should become step/check pipelines

- **Area:** Setup.
- **Finding:** `SetupRunner` and `SetupPreflightChecker` are both large orchestration classes with many default-constructed collaborators and mixed responsibilities.
- **Evidence:** `src/Setup/SetupRunner.php:23`, `src/Setup/SetupRunner.php:45`, `src/Setup/SetupRunner.php:224`, `src/Setup/SetupPreflightChecker.php:10`, `src/Setup/SetupPreflightChecker.php:29`, `src/Setup/SetupPreflightChecker.php:74`.
- **Impact:** Adding setup checks or changing setup order risks regressions because validation, environment writing, process execution, rollback, and reporting are coupled.
- **Recommendation:** Introduce explicit `SetupStepProvider` and `SetupPreflightCheck` services. Let Symfony DI assemble checks/steps instead of using many constructor defaults in production services.
- **Priority:** Before Release.

### F-005 Controller tests protect too much behavior in oversized files

- **Area:** Tests.
- **Finding:** `AdminUserControllerTest`, `UserControllerTest`, and `BackendControllerTest` exceed 1300-2200 lines and mix many workflows in one fixture context.
- **Evidence:** inventory shows `tests/Controller/AdminUserControllerTest.php` at 2226 lines, `tests/Controller/UserControllerTest.php` at 1663 lines, and `tests/Controller/BackendControllerTest.php` at 1386 lines.
- **Impact:** High context cost and higher flake/debug time when one workflow mutates shared fixtures for later workflows.
- **Recommendation:** Split by behavior domain: deleted users, invitations, review queue, group management, profile/password/API keys, backend packages, operations, settings, scheduler. Keep assertions behavior-focused and avoid template-detail drift.
- **Priority:** Now.

### F-006 Visitor identity is stable but not robust enough for future security decisions

- **Area:** Statistics, logging, and session/security planning.
- **Finding:** `VisitorIdGenerator` uses `HMAC(secret, client-ip|lowercase-user-agent)` as the visitor ID.
- **Evidence:** `src/Core/Statistics/VisitorIdGenerator.php:13`, `src/Core/Statistics/VisitorIdGenerator.php:17`, `src/Core/Statistics/VisitorIdGenerator.php:20`, `src/Core/Statistics/VisitorIdGenerator.php:64`.
- **Impact:** This is privacy-preserving and stable for simple analytics, but it collides for NAT/shared devices and changes when IP or user agent changes. It should not become a session-hijack defense by itself.
- **Recommendation:** Keep this for low-risk anonymized statistics for now. For Security work, design a separate session/client-binding strategy using a first-party, rotating, HMAC-protected client signal with clear privacy, DNT, and false-positive handling. Avoid invasive machine fingerprinting.
- **Priority:** Before Security.

### F-007 Request IDs can be externally controlled

- **Area:** Logging and tracing.
- **Finding:** `AccessRequestMetadata` accepts `X-Request-ID` or `X-Correlation-ID` before generating an internal ID.
- **Evidence:** `src/Core/Log/AccessRequestMetadata.php:25`, `src/Core/Log/AccessRequestMetadata.php:32`, `src/Core/Log/AccessRequestMetadata.php:217`.
- **Impact:** Sanitization and length limiting reduce log-injection risk, but external clients can still choose values that collide or confuse operational tracing.
- **Recommendation:** Generate an internal request ID unconditionally and store inbound correlation IDs separately, or only trust inbound request IDs from trusted proxies.
- **Priority:** Before API.

### F-008 UUID primary-key strategy is consistent but should be revisited before schema growth

- **Area:** Doctrine entities and migrations.
- **Finding:** Entities use manually supplied string UUIDs as primary identifiers across public and internal tables.
- **Evidence:** `src/Entity/UserAccount.php:27`, `src/Entity/ContentItem.php:35`, `src/Entity/AccessStatisticEvent.php:29`, `migrations/Version20260531000000.php:103`.
- **Impact:** Public references are stable and portable, but high-write/internal tables pay string-index storage costs and fixture/setup code must create IDs manually.
- **Recommendation:** Keep public UUIDs/slugs where external references matter. Before the next schema expansion, decide whether high-write/internal records should use auto-increment integer primary keys plus public UUIDs where needed. Also evaluate adding `symfony/uid` to replace the custom `UuidFactory`.
- **Priority:** Before API.

### F-009 Statistics aggregation may become expensive as traffic grows

- **Area:** Statistics.
- **Finding:** Access statistics are stored as granular events and aggregation performs multiple grouped queries, including distinct visitor counting and route-label post-processing.
- **Evidence:** `src/Core/Statistics/DatabaseAccessStatisticsRecorder.php:47`, `src/Core/Statistics/AccessStatisticsAggregator.php:86`, `src/Core/Statistics/AccessStatisticsAggregator.php:137`, `migrations/Version20260531000000.php:131`.
- **Impact:** The current model is fine for low traffic and useful for feature discovery, but repeated admin snapshots could become expensive.
- **Recommendation:** Keep granular events for now, but add a documented compaction/read-model strategy before public statistics features grow. Consider scheduled snapshots as the primary admin read model.
- **Priority:** Before Frontend/Release.

### F-010 Package runtime contributions are flexible but hard to document

- **Area:** Package and extension points.
- **Finding:** `PackageRuntimeContributionRegistry` accepts many contribution shapes through `mixed`, iterable recursion, multiple provider interfaces, and direct objects.
- **Evidence:** `src/Core/Package/PackageRuntimeContributionRegistry.php:19`, `src/Core/Package/PackageRuntimeContributionRegistry.php:58`, `src/Core/Package/PackageRuntimeContributionRegistry.php:91`, `src/Core/Package/PackageRuntimeContributionRegistry.php:130`.
- **Impact:** Package authors may struggle to know the preferred extension API. Validation errors happen at runtime and the documented public surface becomes broad.
- **Recommendation:** Introduce a documentation-friendly `PackageContributions` builder or explicit contribution collection while keeping provider interfaces as internal adapters. Prefer one obvious package author path.
- **Priority:** Before API / First-party modules.

### F-011 Twig extension is a UI service hub

- **Area:** View/Twig.
- **Finding:** `ViewTwigExtension` exposes navigation, event hooks, settings forms, package settings, backend actions, themes, debug info, request trace, markdown, and attribute helpers from one extension with many dependencies.
- **Evidence:** `src/View/Twig/ViewTwigExtension.php:36`, `src/View/Twig/ViewTwigExtension.php:74`, `src/View/Twig/ViewTwigExtension.php:110`, `src/View/Twig/ViewTwigExtension.php:121`, `src/View/Twig/ViewTwigExtension.php:230`.
- **Impact:** Template helper ownership and public naming become harder to document and review as the frontend/editor surface grows.
- **Recommendation:** Split by helper family: `NavigationTwigExtension`, `PackageTwigExtension`, `SettingsFormTwigExtension`, `DiagnosticsTwigExtension`, and `MarkupTwigExtension`.
- **Priority:** Before UI/UX Refinement.

### F-012 Domain README files are too thin for current architecture

- **Area:** Documentation drift.
- **Finding:** Several domain README files still contain generic guidance and do not describe the implemented extension points or ownership boundaries.
- **Evidence:** `src/Core/README.md`, `src/Controller/README.md`, `src/Security/README.md`.
- **Impact:** Future contributors and agents cannot use documentation to choose correct modules, especially around Core versus feature-local abstractions.
- **Recommendation:** Update domain READMEs as refactors land. Do not over-document unstable APIs before naming is settled.
- **Priority:** Before Release.

### F-013 Package lifecycle admin mixes read-model composition and package actions

- **Area:** Backend package administration.
- **Finding:** `PackageLifecycleAdmin` is both the package detail read-model builder and the lifecycle action/review facade. It also reads manifests/readmes, sanitizes external URLs, embeds preview images, maps scopes/status tones, and builds action paths.
- **Evidence:** `src/Backend/PackageLifecycleAdmin.php:25`, `src/Backend/PackageLifecycleAdmin.php:45`, `src/Backend/PackageLifecycleAdmin.php:58`, `src/Backend/PackageLifecycleAdmin.php:85`, `src/Backend/PackageLifecycleAdmin.php:107`, `src/Backend/PackageLifecycleAdmin.php:267`, `src/Backend/PackageLifecycleAdmin.php:315`.
- **Impact:** The public backend callable is easy enough to use today, but future package UI/API work will likely duplicate or depend on admin-specific array shapes. The class also hides security-relevant URL/image sanitization inside an admin facade.
- **Recommendation:** Split a `PackageAdminReadModelFactory` or `PackageDetailViewFactory` from a smaller `PackageLifecycleAdmin` action facade. Move external URL and preview-image policy into a reusable package metadata presenter if API responses will expose the same fields.
- **Priority:** Before API / Admin.

### F-014 Console output conventions are duplicated across commands

- **Area:** Console commands.
- **Finding:** Several commands independently implement JSON output, workflow result rendering, issue/message printing, dry-run text output, and success/failure exit mapping.
- **Evidence:** `src/Command/AssetRebuildCommand.php:48`, `src/Command/AssetRebuildCommand.php:130`, `src/Command/PackageAssetSyncCommand.php:47`, `src/Command/PackageDiscoveryCommand.php:40`, `src/Command/SchedulerRunCommand.php:42`, `src/Command/AclGroupApplyCommand.php:35`, `src/Command/PackageLifecycleCommand.php:35`.
- **Impact:** Current behavior is acceptable, but new API/CLI automation surfaces may drift in JSON shape and failure semantics. Reviewers and docs must explain each command separately even though most follow the same pattern.
- **Recommendation:** Add a small `ConsoleResultRenderer` or `WorkflowResultConsoleRenderer` for JSON/text rendering, message formatting, and command exit-code mapping. Keep command classes as thin input adapters.
- **Priority:** Before API / Scheduler expansion.

### F-015 Schema custom Twig needs a clear trust boundary before editor/module work

- **Area:** Content rendering.
- **Finding:** `ContentFieldsetRenderer` renders schema-version `customTwig` through `Environment::createTemplate()` and silently falls back to the generic renderer on errors.
- **Evidence:** `src/Content/Render/ContentFieldsetRenderer.php:17`, `src/Content/Render/ContentFieldsetRenderer.php:21`, `src/Entity/ContentSchemaVersion.php:135`.
- **Impact:** This is useful for trusted system/package schemas, but it is too powerful to expose as ordinary editor-managed data without a policy. Twig templates can call available functions/filters and may leak capability through future extensions.
- **Recommendation:** Before Editor and first-party modules, decide whether custom Twig is restricted to trusted package/system schemas, moved to named templates, or rendered through a sandboxed/limited Twig environment. Document the trust boundary explicitly.
- **Priority:** Before Editor / Security.

### F-016 Public content read model is template-friendly but API-tight to Doctrine entities

- **Area:** Content read API.
- **Finding:** `PublishedContentView` exposes `ContentItem` and `ContentRevision` entities directly alongside resolved fields and access decisions.
- **Evidence:** `src/Content/Read/PublishedContentView.php:16`, `src/Content/Read/PublishedContentView.php:25`, `src/Content/Read/PublishedContentView.php:30`, `src/Content/Read/PublishedContentResolver.php:94`.
- **Impact:** This is ergonomic for Twig and internal rendering, but it couples future API payloads and package consumers to Doctrine entity shape and lazy-loading behavior.
- **Recommendation:** Keep `PublishedContentView` for internal rendering, but introduce a dedicated API/content DTO or serializer boundary before exposing content through the API. Avoid using entities as the documented external extension contract.
- **Priority:** Before API.

### F-017 Account, token, and password flows are still spread across controllers

- **Area:** User management and security-adjacent application flows.
- **Finding:** Admin invitations, registration, password reset, password change review, profile updates, account closure, API key management, and security-review remediation contain substantial workflow logic directly in controllers. Several helper methods repeat token lookup, URL generation, password-policy mapping, audit swallowing, request field parsing, and group repair.
- **Evidence:** `src/Controller/AdminUserInvitationController.php:56`, `src/Controller/AdminUserInvitationController.php:228`, `src/Controller/UserRegistrationController.php:68`, `src/Controller/UserRegistrationController.php:249`, `src/Controller/UserController.php:74`, `src/Controller/UserController.php:223`, `src/Controller/UserPasswordRecoveryController.php:57`, `src/Controller/UserPasswordRecoveryController.php:174`, `src/Controller/UserApiKeyController.php:35`.
- **Impact:** Security review becomes harder because the same account/token invariants are enforced in several private controller methods instead of one named application layer. Future API endpoints would either duplicate these flows or call controllers indirectly, both of which are poor extension surfaces.
- **Recommendation:** Introduce focused application services such as `AccountLinkFlow`, `PasswordRecoveryFlow`, `UserProfileFlow`, `AdminInvitationFlow`, and `ApiKeyManagement`. Controllers should handle request/response, CSRF, and rendering only.
- **Priority:** Before Security / API.

### F-018 Setup wizard controller owns too much state-machine behavior

- **Area:** Setup.
- **Finding:** `SetupController` handles wizard routing, CSRF, live-operation branching, per-step validation orchestration, database test execution, session state persistence, secret protection/unprotection, locale application, reachable-step logic, and result rendering preparation.
- **Evidence:** `src/Controller/SetupController.php:55`, `src/Controller/SetupController.php:78`, `src/Controller/SetupController.php:168`, `src/Controller/SetupController.php:204`, `src/Controller/SetupController.php:241`, `src/Controller/SetupController.php:311`, `src/Controller/SetupController.php:373`, `src/Controller/SetupController.php:438`.
- **Impact:** The setup flow has good coverage but remains expensive to modify because control-flow and persistence details are packed into one HTTP action. This also increases risk when setup logic needs reuse from CLI, dry-run, or support tooling.
- **Recommendation:** Extract a `SetupWizardFlow` or `SetupWizardStateMachine` for step transitions, state persistence, and live/sync apply branching. Keep the controller as a thin adapter around request input and template variables.
- **Priority:** Before Release.

### F-019 Message code/key catalogues are becoming global cross-domain registries

- **Area:** Core message infrastructure.
- **Finding:** `MessageCode` and `MessageKey` centralize constants for manifests, packages, linting, filesystem, operations, setup, access, scheduler, security, content, and more.
- **Evidence:** `src/Core/Message/MessageCode.php:7`, `src/Core/Message/MessageCode.php:24`, `src/Core/Message/MessageCode.php:62`, `src/Core/Message/MessageKey.php:7`, `src/Core/Message/MessageKey.php:34`, `src/Core/Message/MessageKey.php:89`, `src/Core/Message/MessageKey.php:180`.
- **Impact:** The current approach keeps translations deterministic, but it weakens modularity as unrelated features edit the same files. Merge conflicts and documentation drift will grow with API/Security/Editor work.
- **Recommendation:** Keep the `Message` value object, but split message constants by domain or generate a consolidated class from domain catalogues. Public code should remain able to use stable constants without one monolithic file becoming the extension surface.
- **Priority:** Before API / First-party modules.

### F-020 Package lifecycle state transitions are spread across several services

- **Area:** Package lifecycle.
- **Finding:** Activation, deactivation, removal, registry synchronization, fault reset, ZIP replacement, runtime loader faults, and hook failures each mutate package state and sometimes deactivate dependents or trigger asset rebuilds through local orchestration.
- **Evidence:** `src/Core/Package/PackageActivator.php:112`, `src/Core/Package/PackageActivator.php:156`, `src/Core/Package/PackageRemover.php:61`, `src/Core/Package/PackageRegistryHandler.php:31`, `src/Core/Package/PackageFaultResetter.php:34`, `src/Core/Package/Install/PackageZipInstaller.php:255`, `src/Core/Package/PackagePhpLoader.php:181`, `src/Core/Package/PackageRuntimeFailureHandler.php:23`.
- **Impact:** The behavior is reasonably defensive today, but lifecycle invariants are hard to audit because rollback, dependent deactivation, state snapshots, and rebuild decisions are repeated in several places.
- **Recommendation:** Introduce a `PackageLifecycleStateMachine` or small transaction service for status transitions, dependent deactivation, rollback snapshots, and asset rebuild triggers. Keep command/controller/admin facades as thin callers.
- **Priority:** Before First-party modules / Admin expansion.

### F-021 Package manifest mini-languages increase parser and documentation cost

- **Area:** Package manifests and package validation.
- **Finding:** Manifest fields encode structured data in custom string/list syntaxes, and package validation uses bespoke regex/token scanners for dependency pairs, scope lists, and scheduler cron extraction from PHP code.
- **Evidence:** `src/Core/Package/PackageDependencyParser.php:9`, `src/Core/Package/PackageScope.php:51`, `src/Core/Package/PackageSchedulerCronInspector.php:23`, `src/Core/Package/PackageValidator.php:67`, `src/Core/Package/PackageManifestSpec.php:11`.
- **Impact:** The format is lightweight and easy to hand-edit, but each new structured package feature will likely add more custom parsing rules. This makes package-author documentation and validation harder than a typed manifest schema.
- **Recommendation:** Before documenting third-party package APIs, consider moving structured manifest values to YAML/JSON-compatible arrays or a typed manifest DTO while preserving a migration path for current `.manifest` values.
- **Priority:** Before API / First-party modules.

### F-022 Package PHP loaders need an explicit trusted-code policy

- **Area:** Package runtime and extension points.
- **Finding:** Active packages may provide `package.php`, which is required during the main request and may return runtime contributions or callables.
- **Evidence:** `src/Core/Package/PackagePhpLoader.php:46`, `src/Core/Package/PackagePhpLoader.php:65`, `src/Core/Package/PackagePhpLoader.php:111`, `src/Core/Package/PackagePhpLoader.php:171`, `src/Core/Package/PackageRuntimeContributionRegistry.php:23`.
- **Impact:** This is powerful and probably appropriate for first-party/trusted packages, but it must not be presented as a sandbox. Package code can execute arbitrary PHP inside the application process.
- **Recommendation:** Document package PHP loaders as trusted-code extension points, separate them from any future untrusted marketplace/import concept, and consider signed/verified package metadata before external distribution.
- **Priority:** Before First-party modules / Security.

### F-023 Log browsing combines source discovery, scanning, filtering, and pagination

- **Area:** Observability and admin support tooling.
- **Finding:** `LogFileBrowser` owns log-source definitions, glob discovery, reverse line scanning, Monolog parsing, filters, entry IDs, summaries, pagination, and filter option models.
- **Evidence:** `src/Core/Log/LogFileBrowser.php:9`, `src/Core/Log/LogFileBrowser.php:36`, `src/Core/Log/LogFileBrowser.php:93`, `src/Core/Log/LogFileBrowser.php:184`, `src/Core/Log/LogFileBrowser.php:236`, `src/Core/Log/LogFileBrowser.php:287`.
- **Impact:** The class is acceptable for the current small admin view, but it is already over the desired 300-line target and will become harder to evolve if log export, retention, streaming, or API access are added.
- **Recommendation:** Split `LogSourceRegistry`, `LogEntryReader`, `LogEntryFilter`, and `LogPagination` once logs become more than a support/debug screen. Keep the current `LogFileBrowser` as a facade for templates.
- **Priority:** Before API / Support tooling expansion.

### F-024 Translation aggregation is a filesystem transaction service plus catalogue merger

- **Area:** Translation runtime catalogues.
- **Finding:** `TranslationCatalogueAggregator` discovers core/package language roots, parses YAML, merges nested catalogues, detects collisions, hashes sources, stages runtime files, preserves metadata, swaps directories, and recursively copies/removes paths.
- **Evidence:** `src/Core/Translation/TranslationCatalogueAggregator.php:35`, `src/Core/Translation/TranslationCatalogueAggregator.php:57`, `src/Core/Translation/TranslationCatalogueAggregator.php:79`, `src/Core/Translation/TranslationCatalogueAggregator.php:166`, `src/Core/Translation/TranslationCatalogueAggregator.php:227`, `src/Core/Translation/TranslationCatalogueAggregator.php:266`, `src/Core/Translation/TranslationCatalogueAggregator.php:290`, `src/Core/Translation/TranslationCatalogueAggregator.php:378`.
- **Impact:** The current implementation is careful enough for the branch, but it duplicates filesystem transaction patterns also present in package assets/installers and exceeds the preferred file-size target.
- **Recommendation:** Split source discovery, catalogue merging/collision reporting, and runtime-directory transactions. Reuse or introduce a shared atomic directory replacement helper for package assets and translations.
- **Priority:** Before First-party modules / Release.

### F-025 Public event dispatch wraps Symfony events with a stricter registry contract

- **Area:** Extension points and Symfony alignment.
- **Finding:** `PublicEventDispatcher` intentionally fails unregistered public events, records debug hook metadata, reports listener failures, and emits `PublicHookFailedEvent` in addition to Symfony's dispatcher.
- **Evidence:** `src/Core/Event/PublicEventDispatcher.php:38`, `src/Core/Event/PublicEventDispatcher.php:42`, `src/Core/Event/PublicEventDispatcher.php:62`, `src/Core/Event/PublicEventDispatcher.php:80`, `src/Core/Event/PublicEventDispatcher.php:127`, `src/Core/Event/PublicEventHookRegistry.php:19`.
- **Impact:** This gives package authors a documented hook catalogue, but it also means contributors must understand both Symfony events and the project's registry wrapper. If naming drifts, hooks become hard to document.
- **Recommendation:** Keep the wrapper because the project needs explicit public hook metadata, but document that Symfony events are the runtime primitive and `EventHookDescriptor` is the public-documentation contract. Avoid adding undocumented dispatch-only events.
- **Priority:** Before API / First-party modules.

### F-026 Database table prefixing relies on manual SQL rewriting and table inventory

- **Area:** Database portability and Doctrine alignment.
- **Finding:** `PrefixedConnection` rewrites SQL strings with regexes, while `TablePrefix::TABLES` manually lists every prefixed table name.
- **Evidence:** `src/Database/PrefixedConnection.php:25`, `src/Database/PrefixedConnection.php:30`, `src/Database/PrefixedConnection.php:39`, `src/Database/PrefixedConnection.php:93`, `src/Database/PrefixedConnection.php:101`, `src/Database/TablePrefix.php:9`, `src/Database/DoctrineTablePrefixListener.php:14`.
- **Impact:** The current approach supports raw DBAL calls and Doctrine metadata, but SQL rewriting can miss future query shapes or accidentally rewrite string/comment content. The manual table list can drift when migrations add tables.
- **Recommendation:** Prefer Doctrine metadata/table prefixing for ORM-owned queries and add explicit tests for every raw DBAL query path. Consider generating `TablePrefix::TABLES` from Doctrine metadata/migrations or centralizing raw SQL through repository helpers.
- **Priority:** Before API / Schema expansion.

### F-027 ContentItem is a broad aggregate with routing, tree, ACL, schema, localization, and revision state

- **Area:** Content entities and Editor/API readiness.
- **Finding:** `ContentItem` is 575 lines and owns slug routing, parent/sort tree placement, custom URL/redirect data, publication status, schema/current-revision activation, language/variant availability, ACL restrictions, view/edit/manage rules, metadata validation, and revision collection management.
- **Evidence:** `src/Entity/ContentItem.php:33`, `src/Entity/ContentItem.php:45`, `src/Entity/ContentItem.php:51`, `src/Entity/ContentItem.php:57`, `src/Entity/ContentItem.php:71`, `src/Entity/ContentItem.php:83`, `src/Entity/ContentItem.php:90`, `src/Entity/ContentItem.php:251`, `src/Entity/ContentItem.php:290`, `src/Entity/ContentItem.php:340`, `src/Entity/ContentItem.php:448`.
- **Impact:** The entity is still understandable, but it is already above the preferred context size and will become the central pressure point once Editor and API flows add drafts, revisions, permissions, menus, redirects, preview states, and localization. Public naming also starts to drift with `redirectTarget()` and `redirectRoute()` exposing the same backing value.
- **Recommendation:** Keep the current entity stable for now, but split content concerns before Editor/API: route/redirect value object, access rule value object, localization/variant value object, revision activation service, and content tree/sort service. Pick one public name for redirect semantics before documenting API payloads.
- **Priority:** Before Editor / API.

### F-028 ACL rule storage and validation are duplicated across content, schema, and menu entities

- **Area:** Access control and public naming.
- **Finding:** Content items, schema versions, and menu items each store `*MinLevel` plus `*GroupIdentifiers` and each validates optional ACL group lists locally.
- **Evidence:** `src/Entity/ContentItem.php:92`, `src/Entity/ContentItem.php:101`, `src/Entity/ContentItem.php:110`, `src/Entity/ContentItem.php:340`, `src/Entity/ContentItem.php:561`, `src/Entity/ContentSchemaVersion.php:57`, `src/Entity/ContentSchemaVersion.php:66`, `src/Entity/ContentSchemaVersion.php:75`, `src/Entity/ContentSchemaVersion.php:186`, `src/Entity/ContentSchemaVersion.php:299`, `src/Entity/SiteMenuItem.php:46`, `src/Entity/SiteMenuItem.php:126`, `src/Entity/SiteMenuItem.php:137`.
- **Impact:** Behavior is currently similar, but future Security/Admin/API work can easily fix one path and miss another. The repeated shape also makes it harder to document one clear access-rule contract for packages, menus, schemas, and content.
- **Recommendation:** Introduce a small `AccessRule`/`AclRestrictionSet` value object or embeddable plus a shared validator/factory. Use clear operation names such as `viewRule`, `editRule`, `manageRule`, and `useRule` consistently in DTOs and docs.
- **Priority:** Before Security / API.

### F-029 Content entity validation repeats generic UID/string-list helpers

- **Area:** Entity validation and support helpers.
- **Finding:** `ContentItem` and `ContentFieldValue` still duplicate UUID regex validation instead of using `Uid::assert()`, and `ContentItem` contains an unused optional string-list validator.
- **Evidence:** `src/Entity/ContentItem.php:139`, `src/Entity/ContentItem.php:456`, `src/Entity/ContentItem.php:547`, `src/Entity/ContentFieldValue.php:55`, `src/Entity/ContentFieldValue.php:119`.
- **Impact:** The duplicated helpers are small, but they create drift against the repository rule that shared primitives should be reused when they already exist. The only reason not to replace them immediately is that their exception keys/placeholders are content-specific and tests/translations may rely on that.
- **Recommendation:** Either move content-specific UID validation into a reusable `ContentUid` helper or switch to `Uid::assert()` and align error keys/tests intentionally. Remove unused helpers during the ContentItem split.
- **Priority:** Before Editor.

### F-030 NavigationBuilder is a public extension facade and a full navigation engine

- **Area:** Navigation and public hooks.
- **Finding:** `NavigationBuilder` loads persisted menu rows with DBAL, dispatches public extension hooks, maps JSON metadata, filters by access actor, resolves route/URL targets, sanitizes URLs, builds/sorts trees, marks active/ancestor state, slices by level/root, and exports template arrays.
- **Evidence:** `src/Navigation/NavigationBuilder.php:14`, `src/Navigation/NavigationBuilder.php:26`, `src/Navigation/NavigationBuilder.php:53`, `src/Navigation/NavigationBuilder.php:82`, `src/Navigation/NavigationBuilder.php:129`, `src/Navigation/NavigationBuilder.php:224`, `src/Navigation/NavigationBuilder.php:260`, `src/Navigation/NavigationBuilder.php:284`, `src/Navigation/NavigationBuilder.php:316`, `src/Navigation/NavigationBuilder.php:355`.
- **Impact:** The public surface is useful and well documented, but the implementation is already 451 lines and mixes storage, policy, URL security, and tree transformation. Future sitemap, API navigation, or editor-managed menus will likely extend this class unless boundaries are created first.
- **Recommendation:** Keep `NavigationBuilder::build()` and `collectItems()` as the public facade. Split internals into `NavigationItemRepository`, `NavigationAccessFilter`, `NavigationUrlResolver`, `NavigationTreeBuilder`, and `NavigationSliceResolver`. Reuse the access-rule value object from F-028 when it exists.
- **Priority:** Before Editor / Sitemap/API.

### F-031 Locale preference resolution is duplicated and inconsistent

- **Area:** Localization and mail.
- **Finding:** Request locale selection and mail locale selection both read user language settings and supported languages, but they apply different matching rules. `RequestLocaleSubscriber` accepts only exact supported language values, while `MailLocaleResolver` normalizes `_`/`-` and primary-language fallbacks.
- **Evidence:** `src/Localization/RequestLocaleSubscriber.php:42`, `src/Localization/RequestLocaleSubscriber.php:62`, `src/Localization/RequestLocaleSubscriber.php:103`, `src/Mail/MailLocaleResolver.php:17`, `src/Mail/MailLocaleResolver.php:32`, `src/Mail/MailLocaleResolver.php:47`.
- **Impact:** A user preference such as `de_DE` can be accepted for mail but ignored for request locale fallback. This is not dangerous, but it is user-visible drift and will spread when API/Admin/Editor add more locale-aware flows.
- **Recommendation:** Introduce one `LocalePreferenceResolver` or move the normalization fallback into `ContentRouteLocalization`. Use it from request handling, mail, setup/user settings validation, and future API serialization.
- **Priority:** Before API / UI refinement.

### F-032 Renderer-neutral generated forms should stay scoped or move closer to Symfony Form/Validator

- **Area:** Forms and Symfony alignment.
- **Finding:** The custom form layer defines field metadata, inferred input types, HTML validation attributes, typed casting, option validation, pattern checks, and translated error keys outside Symfony Form and Validator.
- **Evidence:** `src/Form/FormFieldDefinition.php:9`, `src/Form/FormInputType.php:9`, `src/Form/FormBuilder.php:16`, `src/Form/FormBuilder.php:58`, `src/Form/FormSubmissionHandler.php:15`, `src/Form/FormSubmissionHandler.php:75`, `src/Form/FormSubmissionHandler.php:171`.
- **Impact:** This is reasonable for package/core setting forms because definitions are renderer-neutral and package-provided, but it can become a parallel form framework if reused for user-facing workflows. Hard-coded `admin.settings.form.errors.*` keys also make the layer less neutral than its namespace suggests.
- **Recommendation:** Document the form layer as a generated settings/config form primitive only, or adapt definitions to Symfony Form/Validator constraints before expanding it to public forms. Rename/generalize error keys if packages or non-admin UIs depend on them.
- **Priority:** Before Admin expansion / First-party modules.

### F-033 SchedulerRunner mixes orchestration, persistence, policy, and reporting

- **Area:** Scheduler.
- **Finding:** `SchedulerRunner` synchronizes tasks, chooses due tasks, enforces runnability/package settings, creates run entities, executes task executors, mutates task status/failure counters, computes next cron runs, validates JSON context, logs soft-budget and failure messages, and builds result arrays.
- **Evidence:** `src/Scheduler/SchedulerRunner.php:38`, `src/Scheduler/SchedulerRunner.php:53`, `src/Scheduler/SchedulerRunner.php:96`, `src/Scheduler/SchedulerRunner.php:119`, `src/Scheduler/SchedulerRunner.php:139`, `src/Scheduler/SchedulerRunner.php:170`, `src/Scheduler/SchedulerRunner.php:223`, `src/Scheduler/SchedulerRunner.php:240`, `src/Scheduler/SchedulerRunner.php:292`, `src/Scheduler/SchedulerRunner.php:330`.
- **Impact:** The scheduler domain is modular around definitions/executors, but the central runner is still the place where most lifecycle invariants live. More scheduler task types, retries, concurrency controls, or admin/API views would increase risk.
- **Recommendation:** Keep `SchedulerRunner::run()` as the facade. Extract `SchedulerDueTaskSelector`, `SchedulerTaskRunRecorder`, `SchedulerFailurePolicy`, and `SchedulerRunReporter` or a task-run transaction service. Re-evaluate how much of this can be delegated to Symfony Scheduler/Messenger before adding distributed or long-running task behavior.
- **Priority:** Before Scheduler/API expansion.

### F-034 Scheduler and live operations use custom file locks instead of Symfony Lock

- **Area:** Scheduler, live operations, and framework alignment.
- **Finding:** Scheduler run locking uses direct `flock()` handles under `var/scheduler/{env}`, while live operations have their own lock/run coordination. Both solve similar cross-process exclusion problems outside Symfony Lock.
- **Evidence:** `src/Scheduler/SchedulerLockFactory.php:7`, `src/Scheduler/SchedulerLockFactory.php:13`, `src/Scheduler/SchedulerRunLock.php:7`, `src/Core/Operation/Live/LiveOperationRunStore.php:274`, `src/Core/Operation/Live/LiveOperationRunStore.php:350`.
- **Impact:** `flock()` is acceptable on ordinary local filesystems, but Symfony Lock would give one documented abstraction for flock/semaphore/redis/database-backed stores and clearer behavior across hosting topologies.
- **Recommendation:** Evaluate `symfony/lock` for scheduler and live-operation run locks. If the custom lock remains, document its filesystem assumptions and add a shared lock helper so the policy is not duplicated.
- **Priority:** Before Security / multi-worker deployment.

### F-035 Child-process environment handling is correct but not obvious at scheduler call sites

- **Area:** Scheduler, process spawning, and operational safety.
- **Finding:** Scheduler command execution passes only `APP_ENV` explicitly, relying on `RunCommandAction` and `PhpCliBinaryValidator` to merge current Dotenv values through `CliProcessEnvironment::fromCurrentProcess()`. Similar call sites in live operations and asset rebuilds also rely on this implicit lower-level behavior.
- **Evidence:** `src/Scheduler/CommandSchedulerTaskExecutor.php:31`, `src/Scheduler/CommandSchedulerTaskExecutor.php:50`, `src/Core/Operation/Process/RunCommandAction.php:83`, `src/Core/Process/PhpCliBinaryValidator.php:27`, `src/Core/Operation/Live/LiveOperationStarter.php:104`, `src/Core/Asset/AssetRebuildQueueFactory.php:81`.
- **Impact:** The current behavior aligns with the branch goal of passing Symfony Dotenv values while filtering web/CGI request context. The risk is documentation and future drift: a new process action could bypass `RunCommandAction` or pass a manually sanitized environment that omits Dotenv values.
- **Recommendation:** Introduce or document a single `ChildProcessEnvironment`/`CliProcessEnvironment` policy and require all direct `Process` construction to use it. Add this as an explicit review checklist item for future subprocess features.
- **Priority:** Now / ongoing.

### F-036 ACL group impact cleanup is a cross-domain JSON scanner

- **Area:** Security, ACL, Content/Menu integration.
- **Finding:** `AclGroupImpactService` inspects and mutates group references across users, account tokens, content items, content schema versions, and site menu items. It scans JSON columns with `LIKE`, maps impact rows for the admin UI, decides whether removal opens public access, and applies cleanup mutations.
- **Evidence:** `src/Security/AclGroupImpactService.php:17`, `src/Security/AclGroupImpactService.php:28`, `src/Security/AclGroupImpactService.php:57`, `src/Security/AclGroupImpactService.php:89`, `src/Security/AclGroupImpactService.php:158`, `src/Security/AclGroupImpactService.php:183`, `src/Security/AclGroupImpactService.php:269`, `src/Security/AclGroupImpactService.php:285`, `src/Security/AclGroupImpactService.php:305`, `src/Security/AclGroupImpactService.php:324`, `src/Security/AclGroupImpactService.php:370`.
- **Impact:** The service is careful enough for current admin flows, but it is brittle as a long-term access-control primitive: new ACL-bearing entities must be manually added, JSON scans are not index-friendly, and public-access risk detection lives in one admin cleanup service.
- **Recommendation:** Introduce a shared ACL reference registry/provider model. Each ACL-bearing domain should expose impact and cleanup operations through a small interface. Reuse the F-028 access-rule value object and consider normalized join tables or generated indexes before ACL-heavy features grow.
- **Priority:** Before Security / Editor.

### F-037 Mail stub logs plain account tokens and must not be treated as production delivery

- **Area:** Account recovery, invitations, mail delivery, and privacy.
- **Finding:** `MessageLogAccountLinkDelivery` records `debug_plain_token` and action URLs in log context, and `AppSecretRotationGuard` uses this delivery path when it issues owner password-reset links after `APP_SECRET` rotation.
- **Evidence:** `src/Security/MessageLogAccountLinkDelivery.php:25`, `src/Security/MessageLogAccountLinkDelivery.php:37`, `src/Security/MessageLogAccountLinkDelivery.php:99`, `src/Security/MessageLogAccountLinkDelivery.php:115`, `src/Security/MessageLogAccountLinkDelivery.php:116`, `src/Security/AppSecretRotationGuard.php:175`, `src/Security/AppSecretRotationGuard.php:183`, `src/Security/AppSecretRotationGuard.php:191`.
- **Impact:** This is acceptable only as a development/setup stub because account links need to be retrievable before a real mailer exists. In production, logs containing password-reset or invitation tokens are sensitive credentials.
- **Recommendation:** Before the Security PR or production release, replace the stub with real mail delivery, make debug-token logging explicitly environment-gated, and redact tokens from persistent logs by default. Add a preflight/admin warning when account-link delivery is still in debug-log mode.
- **Priority:** Before Security / Release.

### F-038 API key encryption and APP_SECRET rotation need a fuller key-management policy

- **Area:** API keys and secret rotation.
- **Finding:** `ApiKeyVault` derives an AES-256-GCM key directly from `APP_SECRET`, stores encrypted API keys as `v1` payloads, and `AppSecretRotationGuard` revokes active API keys plus issues owner reset links when the app secret fingerprint changes.
- **Evidence:** `src/Security/ApiKeyVault.php:20`, `src/Security/ApiKeyVault.php:25`, `src/Security/ApiKeyVault.php:42`, `src/Security/ApiKeyVault.php:73`, `src/Security/AppSecretRotationGuard.php:27`, `src/Security/AppSecretRotationGuard.php:84`, `src/Security/AppSecretRotationGuard.php:136`, `src/Security/AppSecretRotationGuard.php:142`, `src/Security/AppSecretRotationGuard.php:164`.
- **Impact:** The current behavior is conservative because key rotation revokes API keys rather than silently failing decryption, but a real API layer will need clearer operational guarantees around key derivation, key versioning, re-encryption, recovery, audit logging, and what happens when mail delivery is unavailable.
- **Recommendation:** Define key-management policy before API launch: HKDF or Sodium-backed key derivation with context labels, explicit encryption-key versions, documented rotation behavior, and a safe admin recovery path. Keep revocation-on-secret-change unless a tested re-encryption migration exists.
- **Priority:** Before API / Security.

### F-039 Admin user list/review factories mix request parsing, queries, sorting, and view arrays

- **Area:** Admin security UX.
- **Finding:** `AdminUserListViewFactory` and `AdminUserReviewViewFactory` parse request filters, query Doctrine, sort/paginate, and build template array contracts in one place. Some group/review collections are filtered and sorted in memory.
- **Evidence:** `src/Security/AdminUserListViewFactory.php:14`, `src/Security/AdminUserListViewFactory.php:25`, `src/Security/AdminUserListViewFactory.php:61`, `src/Security/AdminUserListViewFactory.php:101`, `src/Security/AdminUserListViewFactory.php:138`, `src/Security/AdminUserListViewFactory.php:207`, `src/Security/AdminUserReviewViewFactory.php:13`, `src/Security/AdminUserReviewViewFactory.php:24`, `src/Security/AdminUserReviewViewFactory.php:66`, `src/Security/AdminUserReviewViewFactory.php:153`.
- **Impact:** Works for current admin volumes, but future audit/review queues, API responses, or large installs will need reusable query services and stable DTOs rather than template-specific arrays.
- **Recommendation:** Split filter parsing from query/read-model generation. Introduce small `AdminUserListQuery`, `AdminGroupListQuery`, and `AdminUserReviewQuery` objects or services. Keep template mappers separate from future API DTOs.
- **Priority:** Before Admin/API expansion.

### F-040 Setup CLI and web input factories duplicate normalization and validation

- **Area:** Setup input handling.
- **Finding:** `SetupCliInputFactory` and `SetupWebInputFactory` both resolve database driver choices, normalize database parts, validate table prefixes, parse default URI/admin email/language inputs, and assemble `SetupInput`.
- **Evidence:** `src/Setup/SetupCliInputFactory.php:18`, `src/Setup/SetupCliInputFactory.php:39`, `src/Setup/SetupCliInputFactory.php:119`, `src/Setup/SetupCliInputFactory.php:197`, `src/Setup/SetupWebInputFactory.php:18`, `src/Setup/SetupWebInputFactory.php:68`, `src/Setup/SetupWebInputFactory.php:160`, `src/Setup/SetupWebInputFactory.php:282`.
- **Impact:** Current behavior is aligned enough, but setup validation can drift between interactive CLI and web setup. That is especially risky for platform requirements and database URL handling because those paths are supposed to be equivalent entry points.
- **Recommendation:** Extract shared `SetupInputNormalizer`/`SetupInputValidator` services and keep CLI/web factories as thin transport adapters. Reuse the same database/default-URI/admin validation and error keys in both flows.
- **Priority:** Before Release.

### F-041 Setup seeding owns too many domain defaults and raw schema details

- **Area:** Setup database seeding and content bootstrap.
- **Finding:** `SetupDatabaseSeeder` writes config rows, owner/admin user records, default content schemas, revisions, content, menus, ACL groups, scheduler tasks, and state markers directly with DBAL arrays.
- **Evidence:** `src/Setup/SetupDatabaseSeeder.php:14`, `src/Setup/SetupDatabaseSeeder.php:35`, `src/Setup/SetupDatabaseSeeder.php:55`, `src/Setup/SetupDatabaseSeeder.php:99`, `src/Setup/SetupDatabaseSeeder.php:149`, `src/Setup/SetupDatabaseSeeder.php:202`, `src/Setup/SetupDatabaseSeeder.php:268`, `src/Setup/SetupDatabaseSeeder.php:330`.
- **Impact:** Raw DBAL seeding is fast and avoids requiring a fully bootstrapped domain model during install, but it duplicates knowledge from content, security, scheduler, and config domains. Future schema/default-content changes can miss setup.
- **Recommendation:** Split into domain seeders such as `SetupConfigSeeder`, `SetupOwnerSeeder`, `SetupContentSeeder`, `SetupNavigationSeeder`, and `SetupStateSeeder`, coordinated by a small setup facade. Keep DBAL if necessary, but move table/column payload ownership closer to the target domain.
- **Priority:** Before Editor / Release.

### F-042 Setup live-operation payload crypto duplicates secret-derived encryption policy

- **Area:** Setup operations and secret management.
- **Finding:** `SetupLiveOperationPayloadProtector` encrypts setup payloads with AES-256-GCM from `APP_SECRET`, while `ApiKeyVault` independently implements a similar direct secret-derived encryption shape.
- **Evidence:** `src/Setup/SetupLiveOperationPayloadProtector.php:13`, `src/Setup/SetupLiveOperationPayloadProtector.php:20`, `src/Setup/SetupLiveOperationPayloadProtector.php:38`, `src/Setup/SetupLiveOperationPayloadProtector.php:63`, `src/Security/ApiKeyVault.php:20`, `src/Security/ApiKeyVault.php:42`.
- **Impact:** Both flows are careful enough for current local payloads, but independent encryption helpers make key derivation, versioning, associated data, expiry/replay policy, and rotation behavior harder to review consistently.
- **Recommendation:** Introduce one small `SecretBox`/`PayloadProtector` style service with HKDF context labels, explicit payload versions, optional associated data, and documented rotation behavior. Use it from setup payloads and API key storage.
- **Priority:** Before Security / API.

### F-043 Setup language catalog overlaps with localization catalogues

- **Area:** Setup localization and supported-language discovery.
- **Finding:** `SetupLanguageCatalog` discovers setup languages from translation source/runtime files, while `TranslationLanguageCatalog` handles available application languages elsewhere.
- **Evidence:** `src/Setup/SetupLanguageCatalog.php:12`, `src/Setup/SetupLanguageCatalog.php:21`, `src/Setup/SetupLanguageCatalog.php:54`, `src/Core/Translation/TranslationLanguageCatalog.php:10`, `src/Core/Translation/TranslationLanguageCatalog.php:19`.
- **Impact:** Setup has valid fallback needs before the full app is installed, but duplicated language discovery can drift from runtime localization behavior.
- **Recommendation:** Keep setup-safe fallbacks, but extract shared language-code discovery/normalization or make `TranslationLanguageCatalog` usable in setup mode without relying on runtime state. Coordinate this with F-031.
- **Priority:** Before UI refinement.

### F-044 Response header public hook lacks an explicit header policy

- **Area:** View response hooks and public extension points.
- **Finding:** `ResponseHeadersEvent` lets public hooks set and remove arbitrary header names/values, and `ResponseHookSubscriber` forwards those instructions directly to Symfony's response header bag.
- **Evidence:** `src/View/Event/ResponseHeadersEvent.php:13`, `src/View/Event/ResponseHeadersEvent.php:36`, `src/View/Event/ResponseHeadersEvent.php:43`, `src/View/Http/ResponseHookSubscriber.php:43`, `src/View/Http/ResponseHookSubscriber.php:57`.
- **Impact:** Symfony will handle many invalid header cases, but the project-level public hook contract does not currently explain which headers package code may alter or how invalid names/values are rejected. Security-sensitive headers could be removed by a trusted package hook without a clear policy.
- **Recommendation:** Add an explicit header allow/deny policy or at least validation in `ResponseHeadersEvent`, and document that response hooks are trusted package code. Consider protecting security headers from removal unless a privileged/core hook opts in.
- **Priority:** Before Security / First-party modules.

### F-045 View injection rendering hides failures without diagnostics

- **Area:** View injections and module diagnostics.
- **Finding:** Dynamic injection slot and route rendering catches all `Throwable` values and silently skips failed package/template output.
- **Evidence:** `src/View/Injection/DynamicViewInjectionRenderer.php:31`, `src/View/Injection/DynamicViewInjectionRenderer.php:45`, `src/View/Injection/DynamicViewInjectionRenderer.php:55`.
- **Impact:** The frontend remains robust when an extension breaks, which is good for production. However, package authors and support diagnostics will struggle to find failed injections if the only visible behavior is missing UI.
- **Recommendation:** Keep graceful rendering, but report failures through the existing message logger/debug collector or package runtime failure path with bounded context. Expose failure counts in admin diagnostics during development.
- **Priority:** Before First-party modules / UI refinement.

### F-046 Detached child-process starters duplicate platform-specific shell behavior

- **Area:** Process spawning, live operations, messenger drain, and platform compatibility.
- **Finding:** `LiveOperationStarter` and `DeferredMessengerDrainProcessStarter` both build detached shell commands, redirect output, write PID markers, and carry separate Windows `cmd /C start` quoting helpers.
- **Evidence:** `src/Core/Operation/Live/LiveOperationStarter.php:91`, `src/Core/Operation/Live/LiveOperationStarter.php:101`, `src/Core/Operation/Live/LiveOperationStarter.php:119`, `src/Core/Operation/Live/LiveOperationStarter.php:126`, `src/Core/Messenger/DeferredMessengerDrainProcessStarter.php:27`, `src/Core/Messenger/DeferredMessengerDrainProcessStarter.php:43`, `src/Core/Messenger/DeferredMessengerDrainProcessStarter.php:50`.
- **Impact:** The current Windows handling was hardened during the portability branch, but duplicated shell detachment is a platform-risk magnet. Future subprocess features could fix quoting, environment, timeout, or PID behavior in one starter and miss the other.
- **Recommendation:** Extract a single `DetachedProcessStarter` with array-command input, output redirection, PID marker policy, and platform-specific implementation. Keep `CliProcessEnvironment::fromCurrentProcess()` as the mandatory environment source and cover Windows command arguments in unit tests.
- **Priority:** Now / Before Security.

### F-047 Internal live-operation JSON routes should be separated from the future public API

- **Area:** Routing, API planning, and operational security.
- **Finding:** Browser-polled live-operation status and continuation endpoints live below `/api/live/operations/*` and authenticate through operation IDs plus query-string tokens.
- **Evidence:** `src/Controller/LiveOperationController.php:26`, `src/Controller/LiveOperationController.php:29`, `src/Controller/LiveOperationController.php:43`, `src/Controller/LiveOperationController.php:46`, `src/Controller/LiveOperationController.php:88`, `src/Controller/LiveOperationController.php:98`.
- **Impact:** The tokenized endpoint is useful for setup/admin live progress and does not depend on cookies. However, `/api` will soon become a documented product surface, while these routes are internal operational plumbing. Query-string tokens are also more likely to appear in browser history, diagnostics, and copied URLs than header/body credentials.
- **Recommendation:** Before the API feature, decide whether internal operational JSON moves to an internal prefix such as `/_studio/live/operations/*` or becomes a documented internal API namespace. Prefer POST/body or short-lived session-bound continuation tokens where practical, and document why polling status tokens may remain in URLs if they do.
- **Priority:** Before API.

## Cross-Cutting Passes

- Production source inventory rechecked: `445` files under `src/`, including `437` PHP files and `449` class/interface/enum/trait signatures.
- Public callable scan rechecked: `2,368` public method/constant signatures in `src/`; no remaining domain was left outside the domain progress table.
- Public extension/entry-point scan rechecked: `343` route, command, Twig helper, public-event, provider-interface, scheduler, operation-action, account-link, and Symfony security integration signals.
- Large-file scan rechecked after current edits: `30` production PHP files remain at or above roughly `300` lines; each highest-pressure file is covered by findings or noted as acceptable for now.
- Low-level randomness scan rechecked: no remaining duplicated UUID-v4 generator was found outside `UuidFactory`; remaining `random_bytes()` uses are tokens, nonces, operation IDs, temporary path suffixes, or request IDs.
- Process-spawning scan rechecked: direct `Process` construction is concentrated in setup preflight/execution, PHP CLI resolution/validation, linting, diagnostics, live operations, messenger drain, and shared `RunCommandAction`; related policy findings are F-035 and F-046.
- Filesystem/symlink scan rechecked: package install/assets, translation aggregation, setup environment writes, operation filesystem actions, and live-operation state are the primary mutation zones; related policy findings are F-001, F-024, F-026, and F-046.

## Foundation Fixes Applied

- Replaced duplicated UUID-v4 generation in `DatabaseAccessStatisticsRecorder` with the shared `UuidFactory`, keeping behavior equivalent while reducing custom drift.
- Replaced remaining duplicated UUID-v4 generation in `PackageRegistryHandler` and `PackageZipInstaller` with the shared `UuidFactory`.
- Replaced duplicated UUID-v4 generation in `StateMarkerRecorder` with the shared `UuidFactory`.
- Replaced duplicated UUID-v4 generation in `AccountTokenIssuer` with the shared `UuidFactory`.
- Replaced duplicated UUID-v4 generation in `SetupDatabaseSeeder` and `SetupPasswordResetRunner` with the shared `UuidFactory`.
- Cleaned small PSR-12 formatting drift in package lifecycle/dispatcher classes discovered during the package audit.
- Cleaned a small PSR-12 formatting drift in `DatabaseAccessStatisticsRecorder` discovered during the observability audit.
- Cleaned a small PSR-12 formatting drift in `ContentSchema` discovered during the entity audit.
- Cleaned small PSR-12 formatting drift in scheduler constructor declarations discovered during the scheduler audit.
- Cleaned a small PSR-12 formatting drift in `AdminUserAccessPolicy` discovered during the security audit.
- Cleaned small PSR-12 formatting drift in setup helper classes discovered during the setup audit.
