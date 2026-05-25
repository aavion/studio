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
  - [ ] Package-scoped theme engine
  - [ ] Native frontend/backend system package and design system
  - Open: first release-readiness verification shape.

- [ ] **0.2.x Security and extension baseline**
  - [ ] Security/ACL baseline
  - [ ] Admin interface and setup UI
  - [ ] Event hooks and Messenger conventions
  - [ ] Package discovery and lifecycle
  - Open: first role/ACL group model; first dashboard widgets; account/password recovery flow; immutable event payload default; package uninstall/data cleanup policy.

- [ ] **0.3.x Structured authoring and resolver foundation**
  - [ ] Schema-driven content fields
  - [ ] Structured editor experience
  - [ ] Draft and publish workflow
  - [ ] Diff and review tools
  - [ ] Media library and file management
  - [ ] Navigation and sitemap builder
  - [ ] Cross-reference index and resolver foundation
  - Open: first minimal field type set; autosave/draft storage; commit vs publish separation; media MIME/upload/thumbnail defaults and exact private-delivery strategy; menu types/depth/sitemap formats; resolver-token/query syntax, depth, loop protection, ACL behavior, and export/import normalization.

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
  - Open: API write scope; public delivery snapshot vs cache-backed read model; operational action-log transport/storage; exact audit log channels/levels/retention; backup/log/submission retention defaults; IconCaptcha provider interface, secret rotation, and asset policy details.

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

- [ ] Keep roadmap sub-items aligned with feature drafts when implementation changes scope, order, or dependencies.
- [ ] Before the first stable `1.0.0` release, keep Doctrine migrations consolidated into one current baseline migration.
- [ ] Add portable read-model/index strategy when JSON-held values such as localized titles need frequent list-view filtering or sorting across MariaDB/MySQL, SQLite, and PostgreSQL.

## Session Logs
**Usage:** Create a new log-entry at the top for every coding session roughly describing every change that's being committed.

### 2026-05-25
- Tightened the package-scoped template contract around canonical Twig namespaces: `@frontend`, `@backend`, and `@root`.
- Reorganized native placeholder templates into frontend/backend areas, leaving the template root for `base.html.twig` and shared macro helpers.
- Added the generic frontend error fallback template and kept 429/503 lightweight through the root wrapper.
- Added `system-template` as a package scope and introduced a template path resolver that lets packages reference `@root` while allowing root overrides only for packages with that scope.
- Added the central HTTP error renderer with `/system/error-pages/{status}` content fallback, frontend status/default templates, anonymous `401` login rendering, and a production exception subscriber for HTTP exceptions.
- Added package template path validation for additive frontend/backend package views, system-template root overrides, and directory-based package macro namespaces under `templates/macros/{package-slug}/**`.
- Documented optional provider slots as stable native Twig stubs backed by the `@provider` namespace, while backend services keep the actual no-op/resolved decision.
- Added deterministic `@provider/{captcha,editor}/**` resolution for captcha fields and rich-text editor fields, with active provider package templates before native base fallbacks.
- Added CodeMirror as the native base editor provider with generic, Markdown-backed rich-text fallback, CSS, HTML, JavaScript, JSON, PHP, TypeScript, and code template stubs.
- Expanded the native template scaffold with frontend/backend layout variants and granular layout, navigation, typography, feedback, action button, toolbar, and form field partials for early theme override points.
- Hardened root-level content routing by storing `/` as the non-null root parent sentinel, keeping `(parent_uid, slug)` uniqueness portable across supported databases.
- Updated feature drafts, developer snippets, class map, translations, and tests for the new template namespace and package-scope contract.

### 2026-05-24
- Completed the first-run/setup baseline: translated interactive `bin/setup`, dry-run planning, env override writing, `composer dump-env {APP_ENV}` with bundled Composer fallback, Doctrine migration execution, default settings, admin seeding, password reset, password confirmation, localized ActionLog output, and callable setup tests.
- Completed the deterministic test-data baseline: PHPUnit now owns `var/test`, applies the baseline migration to SQLite, seeds config, ACL groups, users, API keys, schema/content/menu demo data, and rejects concurrent test-suite runs before shared state can be mutated.
- Completed the first content delivery slice: public read resolver, active-revision field assembly, custom/hierarchy/internal `/system` path lookup, virtual `system` parent support, public controller routes, reserved route prefixes, configured `content.home_path`, `404`/`401`/`403` status mapping, internal/external redirects, redirect loop protection, root-redirect handling, and route `~variant` suffix validation/fallback.
- Completed localization and maintenance foundations: translation-catalogue language discovery, `localization.route_prefixes_enabled`, browser/default language redirects, language-prefix route protection, language fallback warnings, `APP_MAINTENANCE` `503` enforcement, access-level 9 bypass, and admin/login/asset bypass paths.
- Completed shared security/message foundations: ACL actors, capabilities, effective decisions, level-plus-group rules, roleless access-level user bridge, message severities for log filtering, API-key prefix/HMAC/encrypted payload fields, API-key status semantics, and synchronized English/German message keys.
- Updated architecture documentation and class maps for setup, content routing, security, test lifecycle, error-page fallback, `admin/` versus `editor/` route surfaces, and the future external media/file resolver with hidden upstream URLs, optional HTTP-auth metadata, and SSRF-safe boundaries.
- Reworked the extension model around one package lifecycle with `PACKAGE_SCOPE` values, single-active frontend/backend theme and provider scopes, many-active module scopes, all-or-nothing multi-scope activation, `packages/` discovery, package-owned assets, and package-owned data cleanup rules.
- Added the native package-scoped view foundation: frontend/backend layout and partial trees, frontend error/user templates, backend operation templates, generic content fallback rendering with safe Markdown support, namespaced macro templates, system package metadata from the root `.manifest`, and event-collected Twig view context for future package contributions.
- Added the package asset aggregation baseline: stable CSS/JS registry buckets imported by `app.css`/`app.js`, package asset mirror location under `assets/packages/`, inspection for static package assets, CSS/JS path rewriting for package-authored assets, registry builder coverage for Tailwind `@source`, CSS `@import`, and JavaScript import generation, and the documented `studio:assets:rebuild` operation order with final cache clear for ActionLog resilience.
- Implemented the explicit package-aware asset rebuild commands: `studio:packages:assets:sync` mirrors active package assets and rewrites registries, while `studio:assets:rebuild` runs package sync, `assets:install`, `importmap:install`, `tailwind:build`, production-only `public/assets` cleanup plus `asset-map:compile`, and final `cache:clear` through the shared operation executor with dry-run, progress, and JSON output support.
- Hardened package asset rebuild failure handling: dry-runs can still show the planned rebuild when package storage is unavailable, real runs stop before mutation, package/mirror symlinks are rejected, and filesystem read/write/copy/remove failures now become structured operation failures.
- Added the shared raw JSON output renderer for small `/api/live/**` flows such as captcha seeds, polling, and operation status checks, and recorded ActionLog polling fallback semantics with numeric `cursor`, optional `cursor_max`, and server-recommended `next_poll_ms`.
- Added a Scheduler feature draft for future `bin/scheduler` and protected `/scheduler/**` execution, API-key access, Admin UI task definitions, due-task summaries, retry behavior, automatic disablement after three consecutive failures, and package-provided maintenance tasks.

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
