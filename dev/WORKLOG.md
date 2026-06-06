# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-06-06  
> **Owner**: Core  
> **Purpose:** Keeps track of changes and upcoming tasks. 

**Important:** Create a log entry for every commit, describing what's been done and tracking to-dos and follow-up tasks.  
**ALWAYS KEEP UP-TO-DATE!**

## Roadmap
**Usage:** Use as guidance on what major changes to implement next. Keep the list up-to-date while proceeding.

- [x] **0.1.x Foundation**

- [ ] **0.2.x Security and extension baseline**
  - [ ] Admin interface and setup UI

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
  - Open: ActionLog live-operation foundation exists; finish durable audit retention, API write scope, public delivery snapshot vs cache-backed read model, backup/log/submission retention defaults, Scheduler execution implementation, IconCaptcha provider interface, broader secret-rotation policy, and asset policy details.
  - [ ] Logging and statistics
    - [ ] Decide long-term statistic-event compaction after the final reporting dimensions are known; granular anonymized events remain intentionally un-compacted for now.

- [ ] **0.5.x Release lifecycle**
  - [ ] Self-update and release workflow
  - Open: package signature/checksum strategy; direct vs staged updates; rollback scope.

- [ ] **Future**
  - [ ] Neural-like index and semantic resolver
  - [ ] First-party modules and admin add-ons
    - [ ] Referrer/promo system with reusable tokens
    - [ ] CommunityHub
    - [ ] Inline frontpage editor
    - [ ] REI3 tickets integration

## To-Do
**Usage:** Track deferred tasks and keep the list up-to-date.

- ! Keep roadmap sub-items aligned with feature drafts when implementation changes scope, order, or dependencies. Last reviewed: 2026-05-30.
- ! Before the first stable `1.0.0` release, keep Doctrine migrations consolidated into one current baseline migration.
- ! Prefer repository/database queries over full-table PHP filtering for lists, pagination, ACL impact checks, and other scalable read paths.
- Harden database-prefix coverage by keeping Doctrine metadata self-checked against `TablePrefix::TABLES`, validating prefixed ORM metadata, and covering raw DBAL insert/update/join/delete prefix rewriting.
- Removed hard-coded profile language options so profile language choices are generated from the dynamic translation-language catalog, matching the project rule that future languages must not require template or controller rewrites.
- Migrated live-operation runner serialization to Symfony Lock while retaining the runner status file for Admin Operations visibility and making stale cleanup respect still-held framework locks.
- Recorded the child-process environment policy in `.codex/PROJECT_RULES.md` and closed the audit check that application subprocesses use the central Dotenv-preserving, web/CGI-filtering process helpers.
- Closed the detached-process audit check after verifying live-operation and Messenger-drain process starts share `DetachedProcessStarter`; remaining raw process helpers are confined to tests and script linting.
- Closed the live JSON route audit note by recording the D18 boundary: `/api/live/**` remains internal application JSON, while external integrations belong under versioned `/api/v1/**` routes.
- Split scheduler execution into `SchedulerRunner` as a thin facade plus `SchedulerDueTaskSelector`, `SchedulerTaskRunRecorder`, `SchedulerFailurePolicy`, and `SchedulerRunReporter` for due selection, persistent run recording, failure thresholds, and reporting.
- Replaced the workflow-only console renderer with `ConsoleResultRenderer` so console commands share WorkflowResult text/JSON output, JSON payload writing, and status/exit-code mapping.
- Added a central config default provider so missing or unreadable database-backed config keys fall back to registered core setting defaults before explicit caller fallbacks, and aligned setup config seeding to the same DB-free default source for non-input settings.
- Added a shared setup input validator for web setup error-key validation and CLI input assertions, aligning language, URI, database URL/prefix, admin credential, and APP_SECRET length checks while keeping prompt and step filtering transport-specific.
- Fixed content read fallback reporting so implicit/default language resolution does not emit a language-fallback warning when only the requested variant falls back.
- ! Keep Symfony service discovery narrow so DTOs, value objects, messages, events, enums, and other non-services do not bloat the container.
- [ ] Finish the visual design-system pass and first release-readiness verification shape in the UI/UX follow-up.
- [ ] Add portable read-model/index strategy when JSON-held values such as localized titles need frequent list-view filtering or sorting across MariaDB/MySQL, SQLite, and PostgreSQL.
- [ ] Before production readiness, review public package/developer-facing class, interface, function, and Twig helper names for clarity and ergonomics; decide whether to rename directly or provide stable aliases so extension APIs read as intentional rather than provisional.
- [ ] Before PR review for the audit branch, repeat the full project-rules drift audit against the optimized branch state, including namespace/class placement, whether finding decisions were fully applied where possible, and whether the implementation covered related paths beyond the obvious candidates.
- [ ] Evaluate whether the documented minimum memory requirement should become 256M after PHPUnit 13.2/full-suite runs needed a higher CLI memory limit; do not fix this requirement until setup/init/lint/runtime memory behavior has been reviewed across target hosting platforms.

## Session Logs
**Usage:** Create a new log entry at the top for every coding session roughly describing every committed change. At the start of each new feature branch, compact previous session logs by session, move them to [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md), and keep the archived history linked below the current session log.

### 2026-06-06
- Centralized stored ACL group identifier normalization in the existing `AccessRule` value object and aligned content item, schema version, and menu item ACL setters to the shared rule path.
- Split `ContentItem` into a small Doctrine aggregate facade plus focused routing, localization, access-rule, metadata, and revision-state traits with shared input validation, preserving existing columns and public behavior.
- Split live-operation run handling into focused creator, storage, progress writer, presenter, lifecycle/cleanup, runner supervisor, and process-inspection services while keeping `LiveOperationRunStore` as the compatibility facade under the context-size target.
- Split setup execution internals so `SetupRunner` stays under the context target and delegates runtime subprocesses, database-ready environment scoping, nested operation-message extraction, and run-input policy validation to focused services.
- Split setup preflight internals into a thin checklist facade plus focused Composer, Tailwind, process probe, row factory, requirement catalog, detail-row builder, and PHP CLI failure-key mapper services.
- Compacted branch-external worklog sessions into `dev/WORKLOG_HISTORY.md` and shortened the recent pre-audit history block so the active worklog stays focused on the current audit branch.

### 2026-06-05
- Removed the unused optional string-list helper from `ContentItem`; content UID validation already uses the shared `Uid::assert()` helper.
- Split translation catalogue aggregation into source collection, YAML merge/collision handling, and runtime-directory writer services behind the existing aggregate facade/action.
- Documented deferred audit boundaries for visitor/session hardening, custom Twig trust policy, public API content DTOs, and the debug account-link mail stub that cannot be production-hardened until real Symfony Mailer delivery exists.
- Split Admin Logs browsing into a small facade plus source registry, reverse-line reader, entry filter, entry presenter, and pagination helpers.
- Added a shared setup input normalizer for CLI/web database driver, URL, prefix, boolean, and default admin-email handling while leaving step-scoped web validation and interactive CLI prompts transport-specific.
- Split setup database seeding into a small facade plus focused config, admin-account, initial-content, and state-marker writers while keeping seed data centralized in `SetupDefaultSeed`.
- Split package lifecycle admin handling into a thin facade plus focused detail-provider, review-provider, and action-handler services so package metadata presentation and lifecycle mutations no longer share one class.
- Split navigation building internals into focused repository, access-filter, URL-resolver, and tree/slice services while preserving `NavigationBuilder::build()` and `NavigationBuilder::collectItems()` as the public facade for themes and packages.
- Added a shared console workflow result renderer and migrated package lifecycle plus ACL group apply commands to the common issue/message and exit-code rendering path.
- Moved scheduler run locking behind Symfony Lock while keeping the existing scheduler lock adapter and contention behavior intact for cron/API callers.
- Replaced the custom UUID generator with Symfony UID-backed UUIDv7 generation, centralized UID validation through Symfony's UUID parser, normalized UUID-shaped fixtures to valid RFC UUIDs, and hardened package ZIP installer test cleanup so discovery side effects do not leak into later package lifecycle tests.
- Added a central context-labeled secret payload protector for `APP_SECRET`-derived reversible payloads and migrated setup live-operation secret payload protection onto it as the first low-risk path.
- Migrated API key lookup hashes and reversible encrypted payloads onto the shared secret payload protector, including context labels, prefix-bound payload reveal, and aligned seeded test credentials.
- Clarified project rules for `system`-owned technical naming and structured Message-layer diagnostics, then aligned the new secret payload protector with translated system message keys.
- Added a final audit roadmap gate for Message-layer diagnostics, translation-key coverage, deliberate hard throws, and `system` owner naming compliance.
- Added a project rule and final audit gate for dynamic language handling: runtime logic and administrative forms should avoid hardcoded language variants, while intentionally localized Content entities remain allowed to store per-language variants.
- Hardened access request id handling so only short safe upstream correlation tokens are reused, invalid or oversized request-id headers fall back to compact generated ids, and internal request metadata attributes use the `system` owner prefix.
- Extracted a shared cross-platform detached process starter for Live Operations and deferred Messenger drains so output files, PID markers, command quoting, and filtered Dotenv-aware environments share one boundary.
- Added a central locale preference resolver for request, profile, and mail flows so dynamically discovered languages, stale user settings, session/request fallbacks, and default-language behavior share one supported-language policy.
- Flattened ACL group names from fixed English/German maps into one generic administrative name, updated group forms/review/apply flows, and made setup home content seed its available language from setup input rather than a fixed language list.
- Added a `PackageContributions` runtime loader builder so package entry points can group view, settings, and scheduler contributions through named helper methods instead of anonymous mixed arrays.
- Split the monolithic Twig helper extension into context, runtime, and admin helper families while preserving the existing Twig function/filter names.
- Added a response-header hook policy so public package hooks can mutate ordinary safe headers while invalid values and cookie, authentication, transport, content-length, or core security header mutations are blocked.
- Reported failed dynamic view injection rendering through the message layer with bounded context while keeping public rendering graceful for visitors.
- Hardened the dynamic injection diagnostics path so failed message reporting cannot break rendering, and removed the implicit English language default from new `ContentItem` entities.
- Added a phased implementation plan for the project-readiness audit, covering shared runtime foundations, setup and operations, package boundaries, admin/presentation modularity, content/ACL/security foundations, API/data read models, documentation alignment, and suggested commit slices.
- Ran the project-readiness decision interview for audit findings F-001 through F-047, recorded product decisions D1 through D47 in the audit log, and aligned the owning feature drafts for architecture, setup, content, system UI, admin, events, packages, security, navigation, API, scheduler, mailer, and operational workflows.
- Started the issue #57 project-readiness drift audit with a complete production-domain pass across `src/`, recorded architecture/modularity/naming/security/performance/Symfony-alignment findings in `.codex/audit-project-readiness-2026-06-05.md`, and added cross-cutting scans for public entry points, large files, UUID generation, child processes, and filesystem mutation zones.
- Centralized remaining duplicated UUID-v4 generation in statistics recording, package registry/install paths, state markers, account tokens, setup seeding, and setup password reset through the shared `UuidFactory`, with small PSR-12 cleanups discovered during the audit.

### Archived Compacted Session History
- [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md).
