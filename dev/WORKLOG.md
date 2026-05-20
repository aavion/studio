# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-05-20  
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

### 2026-05-20
- Replaced the root README placeholder with a compact project overview and added draft theme/module developer guidelines to the developer manual, including current extension, lifecycle, asset, and UI/UX constraints.
- Added a future first-party modules/admin add-ons draft covering LogViewer/statistics, TinyMCE editor provider, Importer UI, Exporter presets, breadcrumbs, and lightbox/gallery helper modules as optional maintained modules built on core contracts.
- Added a final release-readiness checklist to the draft index and recorded the need for a repeatable release verification path covering setup, tests, translations, assets, smoke checks, backup/restore, security review, documentation, worklog, and class map alignment.
- Clarified that resolver/reference index metadata also supports efficient variable fieldset assembly, and removed neural-index wording from active drafts so that topic remains isolated in the future draft.
- Clarified resolver planning: the resolver index is a lean database-backed metadata/index structure rather than a duplicated resolved content store, runtime content should load from the database where practical, ref/query tokens must support field targets, CodeMirror autocomplete is preferred for resolver tokens, generic variant pretty URLs prefer `/slug/~variant`, and neural-like indexing is moved to a future draft.
- Recorded configuration, lifecycle recovery, media delivery, admin settings, diff, audit/logging, API token, and captcha rate-limit decisions across the feature drafts, including DB-backed admin config precedence, rollback-on-failed theme/module activation, managed non-public file delivery, consolidated settings sections, path-based structured diffs, draft audit event coverage, and captcha rate-limit refunds.
- Specified that newly discovered themes and modules remain inactive until explicitly activated/enabled, and added a manual admin rebuild-assets action that uses the same Tailwind and AssetMapper action-log workflow.
- Documented that theme and module lifecycle changes with frontend contributions trigger explicit Tailwind build and AssetMapper compilation through the operational action-log workflow, while file watchers remain development-only convenience tooling.
- Clarified GeoIP configuration behavior: MaxMind API keys are protected admin-managed database configuration, missing keys disable GeoIP lookup/updates/blocking gracefully, and logs continue with normalized empty location values.
- Propagated Grav-plugin inspiration into the resolver, import/export, operational workflows, contact/logging, security, plugin modules, and IconCaptcha drafts, including two-pass resolver indexing, graph diagnostics, resolver-backed context exports, progressive abuse handling, provider-module fallback behavior, and global captcha form field decisions.
- Expanded the IconCaptcha draft into a concrete optional provider module plan with module-owned assets, deterministic challenge derivation, one-shot validation, Symfony form/validator integration, safer rate-limit direction, failure codes, and implementation decisions.
- Added `.codex/grav-plugin-inspiration-notes.md` with inspiration from the old Grav `refresolver` and `sec-lookup` plugins, covering resolver indexing, GeoIP/security logging, rate-limit redesign, and IconCaptcha concepts without changing feature drafts or copying old code.
- Started shaping the project outline by drafting the project goal from the prepared feature quicknote.
- Added the initial feature-draft roadmap, placeholder draft documents, and README index links for planned core, extension, operations, release, and future features.
- Expanded the project outline with Symfony-first architecture notes, plugin extension categories, draft workflow guidance, implementation expectations, and future-feature constraints.
- Filled the core architecture feature draft with Symfony-first boundaries, extension point rules, namespace proposals, implementation order, validation expectations, and marked architecture decisions.
- Recorded core architecture decisions for namespace scope, `.manifest` metadata, replacement selection, module migrations, and the initial content-only API boundary.
- Clarified that module migrations should prefer explicit module-owned tables for module domain data while keeping variable fieldsets focused on flexible content structures.
- Recorded the public event rule: only documented module/theme extension events are public contracts; undocumented lifecycle events remain internal.
- Expanded the remaining feature drafts into reviewable working guides with Symfony-first technical direction, proposed decisions, required decisions, validation notes, and future-feature constraints.
- Cached official Symfony documentation references in `.codex/symfony-docs-notes.md` for later sessions.
- Added `.codex/framework-version-recap.md` with project-specific offline notes for Symfony 8.0, Doctrine ORM 3.6, DBAL 4.4, Twig 3.25, Tailwind CSS 4.1, Symfony UX, Messenger, Mailer, Security, and PHPUnit usage.
- Incorporated review notes into the error handling draft, including custom error pages, package-level manifest failure handling, debug-only failed operation snapshots, and dry-run safety-check behavior.
- Incorporated review notes into the setup/test automation draft, including release-safe `bin/setup` and `bin/init` flows, `.codex` release exclusion, CLI/web setup sharing, test preconfiguration, and fixture strategy clarification.
- Refined the setup/test automation draft with a `bin/composer.phar` fallback in `bin/init` and an `env:test` SQLite migration plus deterministic SQL seed workflow.
- Clarified in the static/dynamic content draft that content schemas may store database-backed custom Twig for inner fieldset rendering, separate from theme templates and outer layout rendering.
- Incorporated static/dynamic content review notes covering custom Twig validation and safety dictionaries, stable content/entity metadata, query/list fields, reserved route prefixes, hierarchy metadata, versioning, and language/variant routing.
- Propagated content language/variant, field identifier, schema Twig, and controlled content-query decisions into schema fields, editor experience, API, import/export, search, theme engine, and security drafts.
- Recorded missing language variant fallback behavior and expanded content metadata with audit, publication, visibility, ACL restriction, and ownership/responsibility fields; propagated ACL visibility implications to security, API, import/export, and search drafts.
- Refined content metadata with order, optional deletion/trash metadata, optional editing lock metadata, and consistent language/variant field value mapping.
- Added a proposed content data model table for content entities and field values, including a flexible JSON metadata payload with recommended `title` and `subtitle` keys; propagated metadata handling to schema fields, API, and import/export drafts.
- Incorporated the remaining draft review decisions for theme package structure, theme `src/` classes, module translations and uninstall routines, schema versioning, Markdown editing, Messenger mode configuration, setup security, scoped API tokens, JSON-operation imports, backup dry-runs, GeoIP logging, and resolver-based search.
- Added a separate IconCaptcha integration placeholder draft and linked it from the feature draft index so concrete captcha behavior can be reviewed later without blocking the generic captcha extension contract.
- Expanded the content, schema, editor, cross-reference, and import/export drafts with variable fieldset relationships, controlled query fields, inline resolver-token planning, deterministic context gathering, and bounded LLM collaboration exports.
- Added resolver-token export/import normalization notes so internal dynamic tokens can become LLM-readable tagged spans in exports and return to non-destructive internal tokens on import.
- Deferred exact resolver-token and tagged-span syntax until implementation planning, when resolver depth, loop protection, query syntax, functions, validation, ACL behavior, and normalization rules can be designed together.
- Reviewed the complete draft folder for version readiness and replaced the placeholder worklog roadmap with a staged 0.1.x, 0.2.x, 0.3.x, 0.4.x, 0.5.x, and future-feature roadmap, including dependencies and open decisions under each roadmap item.
- Refined the roadmap into dependency-driven implementation phases, moving the security/ACL baseline directly after 0.1.x foundation work and separating structured authoring, operational workflows, and release lifecycle work into clearer stages.
- Renamed feature draft files and index links to match the dependency-driven roadmap before `1.0.0`, avoiding historical version-prefix drift in documentation.
- Condensed the worklog roadmap back into a quick checklist and kept implementation details in the feature drafts.
- Added missing product-surface drafts for the system theme/design system, admin interface/setup UI, and media library/file management, then linked them from the draft index and roadmap.
- Reviewed the old Symfony 7.3 prototype as inspiration only and added missing draft coverage for draft/publish workflow, frontend delivery/caching, admin command/help primitives, media quotas/scanner hooks, and expanded user account recovery/onboarding notes.
- Added an operational admin workflows draft with an interactive action-log pattern for setup, imports, backups, updates, asset/cache rebuilds, and other long-running admin actions.
- Added navigation/sitemap and diff/review drafts after the final old-project sweep, capturing the last useful product concepts before archiving the prototype.

### 2026-05-20
- Cleaned-up repository for better readability and versioning-/branch-handling.
- Minor text-only fixes.

### 2026-05-19
- Removed Symfony skeleton frontend demo code from the main asset entrypoint and deleted the example Stimulus controller.
- Moved ApexCharts and CodeMirror into dedicated Stimulus controllers with lazy lifecycle handling.
- Added basic documentation templates and guidelines.

### 2026-05-15
- Initialized git repository for future use
