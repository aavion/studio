# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-05-27  
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
  - Open: functional native template/system package scaffold exists; finish the visual design-system pass and first release-readiness verification shape in the UI/UX follow-up.

- [ ] **0.2.x Security and extension baseline**
  - [x] Security/ACL baseline
  - [ ] Admin interface and setup UI
  - [x] Event hooks and Messenger conventions
  - [x] Package discovery and lifecycle
  - Open: final Admin UI/UX pass, first dashboard widgets, setup UI refinement, production updater/marketplace, package-owned migration purge execution, final public extension API naming, and one manual package/theme smoke before PR review.
  - Package/theme completion mini-roadmap before PR review:
    - [x] Add the Operations/ActionLog foundation with token-protected action starts, detached runners, polling below `/api/live/operations/{id}`, review-required continuation handoff, an Admin Operations inspection view, and transient run cleanup.
    - [x] Prepare staged ZIP install/update boundaries with enforced manifest slugs, cache-staged uploads, review-required apply, overwrite handling, post-install discovery, reactivation, and nullable registry storage for a future externally discovered available version.
    - [x] Harden the package contribution contract without pretend manifest permission flags; document manifest keys, package settings, runtime `package.php` contributions, static/dynamic view injections, theme scopes, and template namespace precedence.
    - [x] Make deferred Messenger work run soon after dispatch through a post-response `async` drain guarded by an environment-scoped cooldown lock.
    - [x] Cover theme activation, dependency cascades, asset/translation lifecycle, delete/purge semantics, ZIP install, and backend action POST handling with focused tests.
    - [ ] Run one final manual smoke before PR review: fresh setup, package/theme overviews, demo package lifecycle, dependency cascade, ZIP install confirmation, delete vs purge, and setup/public-home behavior.

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
  - Open: ActionLog live-operation foundation exists; finish durable audit retention, API write scope, public delivery snapshot vs cache-backed read model, backup/log/submission retention defaults, Scheduler execution implementation, IconCaptcha provider interface, secret rotation, and asset policy details.

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

- [ ] Keep roadmap sub-items aligned with feature drafts when implementation changes scope, order, or dependencies. Last reviewed: 2026-05-27.
- [ ] Before the first stable `1.0.0` release, keep Doctrine migrations consolidated into one current baseline migration.
- [ ] Add portable read-model/index strategy when JSON-held values such as localized titles need frequent list-view filtering or sorting across MariaDB/MySQL, SQLite, and PostgreSQL.
- [ ] Before `1.0.0`, decide whether package view injections stay `package.php` runtime contributions only or also get a manifest-level syntax; the current package/design PR documents and tests the runtime contribution contract.
- [ ] Before production readiness, review public package/developer-facing class, interface, function, and Twig helper names for clarity and ergonomics; decide whether to rename directly or provide stable aliases so extension APIs read as intentional rather than provisional.

## Session Logs
**Usage:** Create a new log-entry at the top for every coding session roughly describing every change that's being committed.

### 2026-05-27
- Hardened Admin Package detail metadata links so untrusted manifest homepage/source values only become links for safe HTTP(S) URLs; unsafe values render as plain text and the controller coverage now guards the behavior.
- Continued the package review-fix pass: setup password reset logging uses the selected environment, macro validation uses manifest slugs for wrapped ZIPs, malformed dependency declarations are blocked during validation and activation/preflight, runtime `package.php` contributions are committed atomically, and purge is restricted to already removed packages.
- Completed the package lifecycle review-fix pass: active in-place rediscovery queues package-aware asset rebuilds, registry updates keep `installed_version` aligned with `manifest_version`, package removal cascades active dependents, dependency cycles are blocked during activation and installer preflight, and ZIP validation checks translation namespaces against manifest `PACKAGE_SLUG`.
- Hardened ZIP install/update replacement safety: registry-known downgrades are blocked before review, active replacements preflight new dependencies before deactivation, active reverse dependents are restored after replacement, existing package folders stay available until a prepared replacement is ready, and failed discovery or reactivation rolls back to the previous package/status set.
- Completed the access/security review-fix pass: locked setup POSTs cannot execute stale web setup submissions, public static injections cannot shadow protected or otherwise resolved content paths, reserved public injection routes stay out of navigation, persisted menu ACL columns are respected for authorized actors, and inactive or deleted accounts are rejected by Symfony form login.
- Completed the first review-ready Operations/ActionLog slice: detached tokenized live-operation runners, cursor polling below `/api/live/operations/{id}`, retained Admin Operations inspection/detail views, stale cleanup and emergency stale-runner handling, atomic runner claims, a global live-operation lock, overlay resume behavior, review-required continuation handoff, and contextual overlay actions.
- Completed the staged package ZIP installer boundary: enforced `PACKAGE_SLUG`, cache-staged uploads, manifest/package validation, review-required apply, overwrite handling without purge, post-install discovery, reactivation of previously active packages, and nullable available-version registry storage for a future updater.
- Moved package registry refresh into the live-operation path, added the post-response deferred Messenger drain for due `async` jobs, kept non-JavaScript fallbacks, and verified the slice with focused package/operation tests plus container, Twig/YAML, translation, Tailwind, AssetMapper, and full PHPUnit checks.
- Kept supporting contracts and docs aligned: runtime translation catalogues moved to `translations/runtime`, review-required action prompts are enforced, message-layer diagnostics remain durable in the operation message log, and package/install/lifecycle snippets, drafts, class map, and tests were updated.

### 2026-05-26
- Completed the backend/package/admin foundation pass: `/setup`, `/admin`, `/editor`, `/user/*`, account navigation, access-aware backend view registry, Admin Settings forms and sections, package settings storage/metadata, setup redirect, setup web form, setup subprocess environment fallback, logout hardening, and protected-route login callbacks.
- Completed the package contribution and demo pass: shared `public`/`admin`/`editor` view injection surfaces, static and dynamic injections, runtime `package.php` contributions, package setting definitions, demo packages, demo module routes, configurable `/demo` parent, Markdown profile rendering, typography demo, package image/README/source metadata, and package/template lint relaxation.
- Completed the package/theme management pass: package registry overview, package detail metadata, lifecycle review routes, delete/purge/activate/deactivate/reset flows, dependency deactivation cascades, virtual system package metadata/immutability, Theme Management cards, system fallback behavior, backend menu child collapsing, and top-bar action handling on nested routes.
- Completed supporting platform cleanup: language-grouped translation sources with generated runtime catalogues, active package translation aggregation, setup-time `/home` placeholder seed, DBAL baseline migration API update, and verification across targeted backend/package tests plus syntax, Twig, translation, container, and full PHPUnit checks.

### 2026-05-25
- Built the first system UI/design-system slice with tokenized frontend/backend/admin/editor/setup shells, demo preview routes, package-scoped template namespaces, provider fallbacks, CodeMirror provider wiring, and frontend error templates.
- Consolidated package lifecycle foundations: discovery registry persistence, dependency resolution, activation/deactivation, single-active scope handling, fault/reset/remove flows, active package providers, optional `package.php` loading, runtime failure handling, deferred asset rebuilds, and Messenger/cache-warmup discovery triggers.
- Added the public hook/event surface for packages: typed hook descriptors/registry, view/content/render/response events, failure diagnostics, `studio_event_hooks()` metadata, package asset sync observe/extend events, and documented hook boundaries.
- Reworked package assets and translations: scoped asset registry ownership, package asset sync helper splits, JavaScript entry handling, active-package language aggregation, modular source catalogue comparison, and generated default-domain runtime catalogues.
- Refined structured diagnostics and operations logging: message levels, exception/invalid-argument helpers, reporter/logger bridge with redacted `operations.log`, message-backed workflow issues, and clearer WARN/ERROR/SUCCESS/DEBUG semantics across package, content, ACL, setup, and operations flows.
- Kept foundation maintenance current by splitting large seeders/tests/helpers, reorganizing `dev/CLASSMAP.md`, hardening setup/content review findings, and updating drafts, docs, snippets, tests, translations, and worklog references.

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
