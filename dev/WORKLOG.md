# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-05-26  
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

### 2026-05-26
- Added backend routing and account foundations: shared `/setup`, `/admin`, and `/editor` routing with ACL checks and setup locking; `/user/*` login, logout, profile/password, registration/reset/invitation skeletons, user-scoped API-key listing, config-gated account flows, and access-filtered account navigation.
- Hardened logout routing so `GET /user/logout` renders a translated confirmation page while only a CSRF-protected `POST /user/logout` can terminate the session and redirect to the homepage.
- Strengthened setup and configuration behavior: message-backed setup failures, DB-free completion marker persistence, SQLite placeholder resolution, post-completion `cache:clear` for package discovery warmup, Config get/set diagnostics, and test-safe `bin/setup` operation logging based on the selected setup environment.
- Reworked translation handling into language-grouped source catalogues with generated default-domain runtime catalogues, early CLI bootstrap support, active-package aggregation, package namespace/fallback validation, collision checks, Twig default-domain usage, and updated comparison tooling/docs/tests.
- Updated the baseline migration to Doctrine DBAL's primary-key constraint API and kept setup/class map/worklog/tests aligned with the new backend, setup, config, and translation behavior.

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
