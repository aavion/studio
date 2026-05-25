# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-05-25  
> **Owner**: Core  
> **Purpose:** Keeps track of changes and upcoming tasks. 

**Important:** Create a log entry for every commit, describing what's been done and tracking to-dos and follow-up tasks.  
**ALWAYS KEEP UP-TO-DATE!**

## Roadmap
**Usage:** Use as guidance on what major changes to implement next. Keep the list up-to-date while proceeding.

- [ ] **0.1.x Foundation**
  - [x] Core architecture
  - [x] Setup and test automation
  - [x] Error handling and validation
  - [x] Static/dynamic content model
  - [x] Package-scoped theme engine
  - [ ] Native frontend/backend system package and design system
  - Open: native template scaffold exists; finish the visual/design-system pass and first release-readiness verification shape.

- [ ] **0.2.x Security and extension baseline**
  - [x] Security/ACL baseline
  - [ ] Admin interface and setup UI
  - [ ] Event hooks and Messenger conventions
  - [ ] Package discovery and lifecycle
  - Open: first dashboard widgets; setup UI; package activation/install/uninstall flows; package uninstall/data cleanup execution; package service loading for active packages; Messenger mode/routing conventions.

- [ ] **0.3.x Structured authoring and resolver foundation**
  - [ ] Schema-driven content fields
  - [ ] Structured editor experience
  - [ ] Draft and publish workflow
  - [ ] Diff and review tools
  - [ ] Media library and file management
  - [ ] Navigation and sitemap builder
  - [ ] Cross-reference index and resolver foundation
  - Open: content/schema storage baseline exists; first minimal field type UI, autosave/draft storage, commit vs publish separation, media MIME/upload/thumbnail defaults and exact private-delivery strategy, menu types/depth/sitemap formats, resolver-token/query syntax, depth, loop protection, ACL behavior, and export/import normalization remain open.

- [ ] **0.4.x External interfaces and operations**
  - [ ] Operational security and audit coverage
  - [ ] API layer
  - [ ] Frontend delivery and caching
  - [ ] Operational admin workflows
  - [ ] Scheduler
  - [ ] Import/export and LLM collaboration
  - [ ] Backup and restore
  - [ ] Contact, mail, logging, and statistics
  - [ ] IconCaptcha integration
  - Open: API write scope; public delivery snapshot vs cache-backed read model; ActionLog UI/storage beyond current operation output; exact audit log channels/levels/retention; backup/log/submission retention defaults; Scheduler execution implementation; IconCaptcha provider interface, secret rotation, and asset policy details.

- [ ] **0.5.x Release lifecycle**
  - [ ] Self-update and release workflow
  - Open: package signature/checksum strategy; direct vs staged updates; rollback scope.

- [ ] **Future**
  - [ ] CommunityHub
  - [ ] First-party modules and admin add-ons
  - [ ] Inline frontpage editor
  - [ ] Neural-like index and semantic resolver
  - [ ] REI3 tickets integration

## To-Do
**Usage:** Track deferred tasks and keep the list up-to-date.

- [ ] Keep roadmap sub-items aligned with feature drafts when implementation changes scope, order, or dependencies. Last reviewed: 2026-05-25.
- [ ] Before the first stable `1.0.0` release, keep Doctrine migrations consolidated into one current baseline migration.
- [ ] Add portable read-model/index strategy when JSON-held values such as localized titles need frequent list-view filtering or sorting across MariaDB/MySQL, SQLite, and PostgreSQL.

## Session Logs
**Usage:** Create a new log-entry at the top for every coding session roughly describing every change that's being committed.

### 2026-05-25
- Added dependency-resolution debug feedback to package activation planning so activation previews and executions expose the resolved dependency graph without adding request-time runtime-loader log noise.
- Rebalanced `WARN` versus `ERROR` semantics so package-faulty states, invalid manifests, missing required package files/directories, package syntax/namespace/template validation failures, dependency blockers, and package-copy blockers require attention as `ERROR`, while content fallbacks, access denials, and non-applicable lifecycle operations remain `WARN`.
- Reviewed message log levels across the current message producers, added `MessageLevel::Exception` plus `Message::exception()` for caught `Throwable` diagnostics, moved high-level completed lifecycle/operation messages to `SUCCESS`, and downgraded noisy successful access/validation diagnostics to `DEBUG`.
- Added `Message::invalidArgument()` and `MessageException::invalidArgument()` as the canonical shortcut for structured hard invariant diagnostics, replaced verbose `E_INVALID_ARGUMENT` call sites, and documented the throw policy: recoverable runtime failures should return messages/results or controlled error responses, while hard throws are reserved for value-object invariants, setup aborts, and unsafe guard rails.
- Added a minimal message reporting and logging bridge: `MessageReporter` reports one structured message and returns it unchanged, `WorkflowResultMessageReporter` extracts structured messages from workflow results/action logs, `FileMessageLogger` records message translation keys plus redacted JSON context in `var/log/{APP_ENV}/operations.log`, `MessageLevel::Success` separates completed-task feedback from `INFO`, the legacy issue wrapper was removed in favor of direct message-backed issue lists, and `OperationExecutor`, ACL/content feedback, package lifecycle/setup/discovery boundaries, package copy planning, asset rebuild dispatch, PHP-loader failures, and public hook failures now return original results while emitting deterministic diagnostics.
- Added `PackageFaultResetter` as the soft recovery path for faulty packages: it validates the current package folder, preserves compact fault metadata, and resets successful repairs to inactive without deleting data or package files.
- Added package lifecycle boundary services for the current slice: `ActivePackageProvider` gates future loaders to active real packages, `PackageRuntimeFailureHandler` marks identified failing hook owners faulty, and `PackageRemover` removes package directories, marks registry rows removed, and exposes a cleanup boundary for future purge/migration cleanup.
- Removed the unused package `archived` state, aligned Twig namespace registration and package asset sync behind the central `ActivePackageProvider`, and covered lifecycle asset rebuilds so activation/removal batches rebuild once after state changes.
- Added active package PHP runtime loading through optional `package.php`, package source namespace validation for declared `PACKAGE_NAMESPACE`, and documentation for the missing schema-Twig Tailwind aggregation layer.
- Added deferred package asset rebuild messages for active package runtime exits, including PHP loader failures, public hook runtime faults, and discovery transitions that mark active packages `faulty` or `removed`; documented that unchanged faulty packages stay faulty during automatic discovery and only revalidate after meaningful package changes or explicit admin recovery.
- Added deferred package discovery triggers through Messenger: `PackageDiscoveryDispatcher` queues `PackageDiscoveryMessage`, `PackageDiscoveryMessageHandler` executes the runner, and `studio:packages:discover` queues by default with explicit `--run-now` recovery support.
- Added an optional package discovery cache warmer so cache rebuilds can queue the same discovery runner once per cache directory with `cache_warmup` context, a cache-local lock file, and a compact diagnostic payload.
- Added `PackageDiscoveryRunner` as the synchronous package discovery lifecycle executor for Messenger handlers and recovery flows with trigger-aware result aggregation.
- Documented the package discovery trigger policy: no normal request-time discovery, automatic discovery only after fresh container boot or cache rebuild, explicit Admin UI/installer/scheduler/update-check triggers later, installer extraction before discovery, and deferred updater execution through manifest source/version comparison.
- Added activation planning and package dependency resolution for `PACKAGE_DEPENDENCIES`, including missing/status/version checks, dependency auto-activation planning, and conflict previews before lifecycle mutations.
- Added package activation/deactivation orchestration with single-active real-package conflict handling, virtual system package fallback preservation, asset rebuild coordination, and status rollback when rebuilds fail.
- Added the first package lifecycle registry slice: discovered filesystem package candidates can now be reconciled into `extension_package`, new packages are inactive by default, missing packages are marked removed, version/path metadata updates are recorded, and validation failures are marked faulty.
- Added the first navigation builder slice with `NavigationBuilderEvent`, seeded recursive menu rendering in the frontend navigation partial, configurable depth/split-menu output, package item parent/sort normalization, flat item collection for custom theme builders, and debug metadata for public hooks plus Twig namespace path resolution.
- Added `ContentRenderedEvent` as the content-specific post-render hook before the broader HTML output hook runs.
- Added response-level public hooks for themes and modules: `ResponseHeadersEvent`, `OutputGeneratedEvent`, and the main-response `ResponseHookSubscriber` with mutation-on-success behavior and coverage.
- Documented the event-hook decisions and deferred hook backlog: no template-path or runtime asset collection hooks while deterministic namespace discovery and AssetSync cover those paths, no package-defined core permission rules, provider selection remains resolver/lifecycle-owned, and the logger should start with an explicit recorder boundary instead of a generic operations-message event.
- Added the first public EventDispatcher hook surface for packages: `PublicEventInterface`, hook descriptors/registry, class-name based `ViewContextEvent`, package asset sync observe/extend events, subscriber coverage, and package developer documentation.
- Clarified the hook architecture direction: Symfony EventDispatcher and Messenger stay the implementation surface, public hooks remain typed/domain-specific, and adding new hooks should require only a small event class, dispatch point, registry descriptor, tests, and docs.
- Hardened public hook dispatching with a provider-based registry, translation-key hook metadata, a `PublicEventDispatcher` that returns structured issues for listener failures, and a Twig `studio_event_hooks()` debug/admin metadata helper.
- Added the internal `PublicHookFailedEvent` diagnostic signal so later package lifecycle and logging layers can react to hook listener failures without making package deactivation a dispatcher side effect.
- Added the first content rendering hook, `ContentRenderContextEvent`, so themes and packages can extend per-content Twig variables without replacing controller or resolver flow.
- Reorganized `dev/CLASSMAP.md` into stable domain sections with semantic table columns and explicit maintenance rules for deterministic project navigation as the callable index grows.
- Split package asset sync internals into focused filesystem, mirror, and registry writer helpers while keeping `PackageAssetSyncer` behavior and public construction unchanged.
- Split the deterministic PHPUnit database seeder into small config, security, extension, schema, content, and menu seed slices behind the existing `TestDatabaseSeeder` facade.
- Split the public content functional tests by rendering, localization, access, redirect, and error-page behavior, with shared database helpers moved to a controller test trait.
- Completed the package-scoped template baseline: canonical Twig namespaces (`@frontend`, `@backend`, `@root`, `@provider`), `system-template` scope, package template path validation, provider fallbacks, and CodeMirror as the native editor provider.
- Reorganized the native template scaffold into frontend/backend/provider areas with root `base.html.twig`, generic frontend error fallbacks, shared macros, layout variants, and granular partials for future theme overrides.
- Added the central HTTP error renderer with `/system/error-pages/{status}` content fallback, frontend status/default templates, anonymous `401` login rendering, and a production exception subscriber for HTTP exceptions.
- Hardened package asset handling: `.mjs` app/index/module/theme entrypoints now enter JavaScript registries, dynamic `import()` string specifiers are rewritten during mirroring, vendor assets remain isolated, and non-JSON asset command issue rendering no longer masks failures.
- Hardened setup/content review findings: root-level content uses `/` as a non-null parent sentinel for portable slug uniqueness, setup ACL group seeding preserves existing primary keys, env writes fail loudly, and unsupported SQLite URL variants are rejected during preparation.
- Updated feature drafts, developer snippets, class map, translations, worklog notes, and tests for the template, package, setup, and content-routing contracts.

### 2026-05-24
- Completed the first-run/setup and deterministic test-data baselines: localized interactive setup, dry-run planning, env persistence, migrations, default settings/admin seeding, password reset, SQLite test bootstrap, demo data, and sequential test-suite protection.
- Completed the first public content delivery slice: published content reads from active revisions, hierarchy/custom/internal `/system` routing, reserved prefixes, home-path config, `401`/`403`/`404` mapping, redirects, redirect-loop protection, localization redirects/fallbacks, variant suffix fallback, and `APP_MAINTENANCE` `503` handling.
- Completed shared security/message foundations: access actors, level-plus-group ACL rules, roleless access-level user bridge, API-key status/encryption metadata, structured message severities, and synchronized English/German catalogues.
- Reworked extensions into one package lifecycle with `PACKAGE_SCOPE`, single-active theme/provider scopes, many-active module scopes, all-or-nothing multi-scope activation, `packages/` discovery, package-owned assets, data-cleanup rules, and native package-scoped view/template foundations.
- Added the package asset pipeline baseline and commands: CSS/JS registry buckets, `assets/packages/` mirroring, Tailwind source discovery, CSS/JS path rewriting, `studio:packages:assets:sync`, `studio:assets:rebuild`, dry-run/progress/JSON output, production compile steps, and structured failure handling.
- Added operational foundations and documentation updates: raw JSON output renderer for `/api/live/**`, ActionLog polling cursor semantics, scheduler draft, external media/file resolver draft, admin/editor route decisions, class maps, and related architecture notes.

### 2026-05-23
- Added the first persistent Core/content database baseline in migration `Version20260523210000`: global config, ACL groups, users, API keys, extension packages, menus, database-backed schemas, schema versions, content items, revisions, and revision-scoped field values.
- Introduced schema/content primitives for status, visibility, slug and route-prefix validation, required `title`/`subtitle` field identifiers, schema sources, nullable active schema/revision pointers, revision diff/import readiness, and level-plus-group ACL override fields.
- Added the shared `Message` model with machine-readable codes, translation keys, parameters, diagnostic context, exception support, synchronized English/German catalogues, and coverage; wired package, manifest, lint, filesystem, process, operation, and content validation flows through it.
- Configured SQLite for the test environment at `var/test/test.db`; the PHPUnit bootstrap now clears `var/test`, applies migrations, and verifies the baseline migration against SQLite.
- Documented pre-`1.0.0` migration/compatibility rules, database portability, filesystem-backed structured logging direction, Markdown/EditorConfig hardbreak rules, schema preset expectations, and portable read-model follow-ups.
- Updated class map, drafts, developer snippets, namespace READMEs, env defaults, and placeholder cleanup to match the new baseline.
- Verified with PHPUnit, translation catalogue sync, YAML syntax, Symfony container linting, full Doctrine schema validation against SQLite, and the EditorConfig audit.

### 2026-05-22
- Prepared the first Core architecture baseline: source namespace boundaries, `bin/init`, `bin/setup`, illustration CSS sync, Apache/nginx/IIS templates, manifest/package discovery, package validation, reusable lint providers, filesystem/integrity helpers, action logs, structured diffs, dry-runs, action queues, operation execution, filesystem/process actions, package operation planning, and structured exports.
- Added supporting docs and fixtures: developer-manual snippet pages for Core/package/import/security/deployment/UI/release/test topics, valid and intentionally invalid package fixtures, visible `TestSuiteLifecycle` notes, and deferred design decisions for SVG theme coloring, dependency maps, and diagnostics models.
- Added and refined automation/test tooling: `.codex` cleanup helpers for ignored artifacts and iCloud/Finder conflicts, shared PHPUnit filesystem helpers, suite-root temporary directories, centralized fixture paths, symlink test helpers, and smoke/negative coverage for fixtures and package preflight diagnostics.
- Hardened the review baseline against traversal, symlink, status-downgrade, Composer metadata, and base-path redirect regressions; updated class map, drafts, documentation, and PHPUnit coverage accordingly.
- Verified by cleaning ignored artifacts, rebuilding with `bin/init`, and re-running PHPUnit, container linting, Composer validation, Markdown link checks, and whitespace checks.

### 2026-05-20
- Created and consolidated the feature-draft roadmap for 0.1.x through 0.5.x plus future features, including core architecture, content modeling, themes, modules, security/ACL, editor workflows, resolver/search, media, import/export, operations, backup/restore, IconCaptcha, release lifecycle, and first-party module candidates.
- Refined the drafts with review decisions for Symfony-first architecture, `.manifest` metadata, public event rules, module/theme lifecycle and rollback, DB-backed admin configuration, ACL visibility, content metadata, language/variant routing, schema Twig, resolver-token planning, import/export normalization, operational action logs, release readiness, and production-oriented validation.
- Captured resolver and security inspiration from the old Grav plugins in `.codex/grav-plugin-inspiration-notes.md`, then translated the useful concepts into drafts without copying old code: lean DB-backed resolver indexes, fieldset assembly support, GeoIP/logging behavior, progressive abuse handling, and modular IconCaptcha.
- Added or expanded supporting documentation: root README project overview, developer manual entry point, draft theme/module developer guidelines, framework documentation cache/recap files, and the draft index release-readiness checklist.
- Reviewed old Symfony/Grav project material as inspiration only and added missing product-surface or future drafts, including system theme/design system, admin/setup UI, media library, draft/publish workflow, frontend delivery/caching, operational admin workflows, navigation/sitemap, diff/review tools, neural-like resolver, and first-party admin add-ons.
- Bundled Composer 2.9.8 as `bin/composer` for future fallback-logic.

### 2026-05-20
- Cleaned-up repository for better readability and versioning-/branch-handling.
- Minor text-only fixes.

### 2026-05-19
- Removed Symfony skeleton frontend demo code from the main asset entrypoint and deleted the example Stimulus controller.
- Moved ApexCharts and CodeMirror into dedicated Stimulus controllers with lazy lifecycle handling.
- Added basic documentation templates and guidelines.

### 2026-05-15
- Initialized git repository for future use
