# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-05-20  
> **Owner**: Core  
> **Purpose:** Keeps track of changes and upcoming tasks. 

**Important:** Create a log entry for every commit, describing what's been done and tracking to-dos and follow-up tasks.  
**ALWAYS KEEP UP-TO-DATE!**

## Roadmap
**Usage:** Use as guidance on what major changes to implement next. Keep the list up-to-date while proceding.

- [ ] Create feature drafts and project outline

## To-Do
**Usage:** Track deferred tasks and keep the list up-to-date.

- [ ] Review and resolve remaining `Decision required` items in the feature drafts.

## Session Logs
**Usage:** Create a new log-entry at the top for every coding session roughly describing every change that's being committed.

### 2026-05-20
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

### 2026-05-20
- Cleaned-up repository for better readability and versioning-/branch-handling.
- Minor text-only fixes.

### 2026-05-19
- Removed Symfony skeleton frontend demo code from the main asset entrypoint and deleted the example Stimulus controller.
- Moved ApexCharts and CodeMirror into dedicated Stimulus controllers with lazy lifecycle handling.
- Added basic documentation templates and guidelines.

### 2026-05-15
- Initialized git repository for future use
