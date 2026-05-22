# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-05-22
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
  - Open: first deterministic SQL seed shape; `title`/`subtitle` JSON metadata vs indexed columns.

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

## Session Logs
**Usage:** Create a new log-entry at the top for every coding session roughly describing every change that's being committed.

### 2026-05-22
- Prepared the first source namespace skeleton from the 0.1.x through 0.5.x feature drafts with documented `src/Core`, `src/Content`, `src/Theme`, `src/Module`, `src/Security`, `src/Editor`, `src/Integration`, and `src/Operations` boundaries.
- Added the first shared Core workflow baseline with `OperationStatus`, `OperationIssue`, and `OperationResult` for recoverable operation states.
- Added PHPUnit coverage for Core workflow issues and results, including success, invalid, review, blocked, failed, and invalid-construction guard behavior.
- Added `bin/init` to verify PHP version and required extensions, resolve Composer with a `bin/composer` fallback, install Composer packages, install ImportMap assets, build Tailwind CSS, resolve Symfony's environment through `Dotenv::bootEnv()`, and compile the AssetMapper only for `prod`.
- Updated `bin/init` to bootstrap with `composer install --no-dev --no-scripts`, then run the final Composer install with dev dependencies only for `dev` and `test`.
- Added `--optimize-autoloader` to both Composer install phases in `bin/init`.
- Removed redundant explicit `importmap:install` and `tailwind:build` calls from `bin/init` because Composer auto-scripts already execute ImportMap installation, public asset installation, and Tailwind builds.
- Regenerated `assets/styles/illustrations.css` from the actual files in `assets/illustrations/undraw` to fix stale illustration class and filename mismatches.
- Recorded the SVG theme-color limitation in the system theme draft: background SVGs do not inherit CSS custom properties, so dynamic unDraw coloring should use an allowlisted inline SVG renderer or Twig component later.
- Added `bin/setup` as a non-mutating first-run setup skeleton with intentionally deferred repository initialization, installation data collection, configuration writing, and persistence preparation phases.
- Added static PHPUnit coverage for the init script's presence, executable bit, PHP syntax, and required initialization steps.
- Added static PHPUnit coverage for the setup skeleton.
- Updated the class map with the new Core workflow value objects.

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
