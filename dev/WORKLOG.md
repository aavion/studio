# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-05-24  
> **Owner**: Core  
> **Purpose:** Keeps track of changes and upcoming tasks. 

**Important:** Create a log entry for every commit, describing what's been done and tracking to-dos and follow-up tasks.  
**ALWAYS KEEP UP-TO-DATE!**

## Roadmap
**Usage:** Use as guidance on what major changes to implement next. Keep the list up-to-date while proceding.

- [ ] **0.1.x Foundation**
  - [ ] Core architecture
  - [ ] Setup and test automation
  - [ ] Error handling and validation
  - [ ] Static/dynamic content model
  - [ ] Theme engine
  - [ ] System theme and design system
  - Open: first release-readiness verification shape.

- [ ] **0.2.x Security and extension baseline**
  - [ ] Security/ACL baseline
  - [ ] Admin interface and setup UI
  - [ ] Event hooks and Messenger conventions
  - [ ] Plugin module discovery and lifecycle
  - Open: first role/ACL group model; first dashboard widgets; account/password recovery flow; immutable event payload default; module uninstall/data cleanup policy.

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

### 2026-05-24
- Added the shared first-run setup runner and `bin/setup` CLI adapter, covering translated interactive setup prompts, installer language discovery, dry-run planning, env override writing, APP_SECRET/default URI/database URL handling, `composer dump-env {APP_ENV}` with bundled Composer fallback, Doctrine migration execution, default site settings, ACL/admin seeding with the `registered` level 1 group, halt-on-error ActionLog results, and callable setup tests.
- Added the first low-priority public content routes and controller, rendering seeded content through the published read layer with route-prefix guarding, language/variant query support, `404`/`403` status mapping, and functional coverage for root, custom paths, missing content, unpublished content, missing variants, private content, ACL denial, and reserved prefixes.
- Added the first public content read layer with published slug/custom URL/hierarchy path lookup, active-revision field assembly, language fallback, missing-variant unavailable-context handling, explicit resolution statuses, visibility checks, direct view ACL enforcement, and coverage against the seeded SQLite content data.
- Added deterministic SQLite demo seeds to the PHPUnit bootstrap lifecycle, covering config defaults, ACL groups, admin/API records, preset schemas, published content with active revisions and localized fields, and the main navigation menu; the seeded admin login uses `admin` with the current `APP_SECRET` as password, and API keys cover read-write, read-only, and revoked states.
- Switched API-key persistence to display prefixes, APP_SECRET-derived HMAC lookup hashes, and APP_SECRET-derived encrypted payloads, then added API-key status semantics and reusable message catalogue keys for status labels and common API-key feedback.
- Added log-filterable message levels and the first shared ACL resolver primitives for actors, capabilities, inherited rules, effective decisions, and granted/denied resolver messages.
- Extended operation results and action-log entries with non-blocking messages, then classified manifest, package, filesystem, process, operation, validation, and ACL output across `ERROR`, `WARN`, `INFO`, and `DEBUG` levels.
- Added operations coverage for seeded ACL, schema, content, field, and menu data, and updated setup/test documentation to clarify that `env:test` no longer depends on `bin/setup`.

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
