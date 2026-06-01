# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-05-31
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
- ! Keep Symfony service discovery narrow so DTOs, value objects, messages, events, enums, and other non-services do not bloat the container.
- [ ] Finish the visual design-system pass and first release-readiness verification shape in the UI/UX follow-up.
- [ ] Add portable read-model/index strategy when JSON-held values such as localized titles need frequent list-view filtering or sorting across MariaDB/MySQL, SQLite, and PostgreSQL.
- [ ] Before production readiness, review public package/developer-facing class, interface, function, and Twig helper names for clarity and ergonomics; decide whether to rename directly or provide stable aliases so extension APIs read as intentional rather than provisional.

## Session Logs
**Usage:** Create a new log-entry at the top for every coding session roughly describing every change that's being committed.

### 2026-06-01
- Addressed setup review hardening: rollback now restores pre-existing env files, snapshots database tables before setup and only drops tables created by the failed run, reports restore problems without hiding the original failure, and surfaces a visible rollback summary in ActionLog output; CLI/database prefixes normalize like the web wizard, can be explicitly cleared, load target `--env` defaults for password reset, and prefix migration object names; Doctrine migration metadata remains unprefixed, prefixed setup password resets work, setup live-operation/session secrets are encrypted before persistence and cleaned after completion, review-required live-operation continuations survive reload/continue gaps and align client retention with server cleanup, prefix-aware Messenger readiness checks queue package work correctly, dry-run setup avoids SQLite file creation, SQLite no-JS database forms avoid server-field browser blocks, the wizard can clear stored database passwords, non-JS setup apply renders an HTML fallback, and Composer install/update prepares ignored package registry stubs before asset scripts.

### 2026-05-31
- Built `feat-setup-wizard`: a DB-free, step-gated setup wizard with preserved input state, live language switching, field-level validation, driver-aware database input, optional table prefixing, site/default settings, OWNER account creation, review/apply flow, LiveOperation execution, rollback cleanup, and setup completion marking only after successful apply.
- Hardened setup runtime boundaries: normal app services stay off Doctrine before setup completion, `/api/live/*` remains available for JSON polling, DB test/apply are the only pre-completion DB opt-ins, stale browser operation state is ignored, setup prefers bundled Composer when needed, and failure paths report message-backed errors instead of invisible request work.
- Added setup preflight coverage for public webroot, PHP version, required/optional PDO extensions, Composer, CLI subprocess support through Symfony Process, writable paths, and guarded auto-heal; database driver choices now only expose loaded PDO drivers and backend validation rejects unavailable drivers.
- Integrated setup-specific security and data policies: shared password policy and match meter across setup/user password flows, 12-character custom hash-salt minimum, deterministic APP_DATABASE_PREFIX handling, prefixed-database seeding under the migration environment, and manifest-backed default project naming.
- Polished setup and system UX: dark backend canvas with bright setup shell, responsive landing hero, app-aware copy, tabular preflight details, compact footer step checklist, icon navigation, system footer partials, responsive/error-shell hardening, and a root-scoped dismissible alert stack shared by setup/backend/frontend flashes.
- Moved package discovery and translation aggregation out of cache warmers into setup/admin-triggered flows, narrowed Symfony service discovery to reduce cold-container pressure, and confirmed cold `bin/init` no longer OOMs under the default memory limit.
- Cleaned generated package assets and registries out of Git tracking while keeping anchors and `bin/init`/asset sync recovery paths; the isolated `improve-asset-rebuild` branch hardened generated asset/translation swaps to preserve previous state on rebuild failures.
- Finished follow-up hardening from user-management review rounds: elevated deleted-account reactivation requires admin approval, ACL/account-link stale-state paths are guarded, state markers stay atomic with mutations, menu/content ACL delete warnings cover public-exposure edges, deleted-user cleanup uses the newest deletion marker, and the Messenger drain honors the configured Doctrine queue.
- Renamed the product display name to `Studio`, refreshed README/docs/translations/runtime catalogues/tests/class map for the setup/user-management slice, and kept focused setup/security/controller verification green.

### 2026-05-30
- Finished the ACL role refactor: one exact account role plus optional groups with minimum-role floors, Symfony hierarchy/firewalls, owner guardrails, optional default groups, no obsolete locked-group behavior, updated migrations/setup/UI/translations/docs/class map, and regression coverage.
- Hardened user-management flows end to end: invitation, registration, approval, recovery, security review, deleted-account reactivation, profile uniqueness, API keys, APP_SECRET rotation, enumeration boundaries, token delivery, and structured message/reporting paths.
- Improved developer tooling with focused `bin/lint` targets, environment-scoped generated translation catalogues, runtime catalogue cleanup, deterministic low-memory `bin/init`, Composer 2.10.0, README updates, and full-suite verification.

### 2026-05-29
- Updated Symfony and related dependencies to 8.1-era versions, fixed exposed test-suite drift, and identified container/init memory pressure as the main blocker before continuing the user-management review.

### 2026-05-28
- Built and reviewed the user-management foundation: invitation-first onboarding, registration modes, profile/password/API-key management, password reset, account-link acceptance, self-service closure, deleted-account retention/reactivation, admin review queues, searchable user/group management, and token lifecycle maintenance.
- Split large user-management controllers into focused controllers and shared helpers, then hardened review-reported security edges around last-owner policy, token delivery/reissue/revocation, stale disputes, deleted users, revoked API keys, secret rotation, duplicate identities, ACL cleanup warnings, and defensive state metadata recording.
- Verified the slice with full PHPUnit plus focused controller/settings/setup/entity/command/mail/security/backend route coverage, syntax, Twig/YAML/container linting, translation comparison, and documentation/class-map updates.

### 2026-05-27
- Completed package/design review hardening, staged ZIP install/update boundaries, live-operation ActionLog execution, deferred Messenger drains, setup/vendor recovery, and package registry refresh through operations.
- Added the logging/statistics/audit foundation with dedicated message/audit/access channels, redaction, admin log/statistics views, security settings, trusted-client-IP handling, DNT/statistics controls, and support IDs on error pages.
- Kept migrations, docs, class map, translations, and verification aligned with full PHPUnit plus targeted migration/setup/container/schema/syntax checks.

### 2026-05-26
- Completed backend/package/admin foundations: setup/admin/editor/user surfaces, access-aware backend registry, Admin Settings, package settings/metadata, package contribution surfaces, demo packages/routes, theme/package management, lifecycle review flows, and generated runtime translations.
- Verified backend/package behavior with targeted tests, syntax, Twig, translation, container, and full PHPUnit checks.

### 2026-05-25
- Built the first system UI/design-system shells, package lifecycle registry, package hook/event surface, scoped package assets/translations, structured diagnostics, and operations logging conventions.
- Split early large helpers/tests, updated docs/class map/drafts/translations, and hardened setup/content review findings.

### 2026-05-24
- Completed first-run setup, deterministic test bootstrap, initial public content delivery, shared security/message primitives, package lifecycle scoping, package asset pipeline commands, and ActionLog/live-operation foundations.

### 2026-05-23
- Added the first persistent Core/content database baseline, schema/content primitives, shared `Message` model, SQLite PHPUnit bootstrap, pre-`1.0.0` migration rules, class-map/docs updates, and baseline verification.

### 2026-05-22
- Prepared the Core architecture baseline: init/setup scripts, web-server templates, package discovery/validation, lint providers, filesystem/process/operation helpers, package operation planning, fixtures, docs, and early review hardening.

### 2026-05-20
- Created the initial feature-draft roadmap, Symfony-first architecture decisions, package/theme/module lifecycle drafts, resolver/security inspiration notes, developer documentation entry points, release-readiness checklist, and bundled Composer fallback.

### 2026-05-19
- Removed Symfony skeleton demo code, moved ApexCharts/CodeMirror into dedicated lazy Stimulus controllers, and added documentation templates/guidelines.

### 2026-05-15
- Initialized the repository.
