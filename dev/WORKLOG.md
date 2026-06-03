# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-06-01
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

### 2026-06-03
- Started `feat-php-cli-resolver`: added a shared PHP CLI resolver for web-hosted setup and background processes, with explicit preflight diagnostics for safe mode, disabled process functions, server-config-blocked CLI, and unresolved PHP binaries; wired setup preflight, setup runner, Composer phar execution, live operations, Messenger drain, scheduler command execution, and asset/backend command queues to use the resolved PHP CLI command prefix.

### 2026-06-01
- Updated tailwind-binary to v4.3.0 and composer dependencies
- Started `feat-scheduler`: aligned the Scheduler draft with `/cron/run`, API-key triggering, `job={job_id}` direct runs, cron expressions, Symfony Scheduler/Messenger integration, DB-backed task/run state, package task policy, admin UI expectations, and failure/logging behavior; added `symfony/scheduler` plus cron-expression support, built the first runner/endpoint/Admin-view foundation, then hardened run-now, lock/GET-auth behavior, soft-budget diagnostics, cron validation, public endpoint failure handling, stale task direct access, task default/reactivation state, package-provided task registration via the central scheduler registry, compact cron syntax help, CLI command coverage, focused Admin scheduler UI coverage, stricter cron API-key authorization, core maintenance tasks for statistics snapshots/cache/package discovery/cache clearing, opt-in web-traffic triggering through the post-response Messenger drain, and review-reported operational edges around forced runs, package policy/provider retention, metadata encoding, CLI/web failure status, route-generated cron URLs, detached drain failures, scheduler-route drain skips, aliased cron validation, and setup PHP/extension preflight checks.
- Hardened late scheduler review edges around exact cron-call parsing, namespace aliases, unrelated scheduler-definition class names, in-call comment handling, Admin run-now feedback, statistics snapshot storage failures, web-triggered PHP invocation, package ActionQueue confirmation resets including target changes, and Composer preflight recovery.
- Completed setup-wizard review hardening: made rollback/env/database-prefix/live-operation/session-secret/no-JS/dry-run/database-driver paths safer, kept generated package registry stubs available before asset scripts, and verified setup flows through focused and full-suite checks.
- Completed the test-suite audit branch: documented the audit in `.codex`, narrowed PHPUnit discovery, removed `.codex` helper-script tests from the project suite, trimmed UI/CSS/Stimulus-heavy assertions, consolidated duplicate route-smoke coverage, hardened ZIP-installer test cleanup, and measured the suite at `791 tests`, `4876 assertions`, peak `121 MB`; package-installer fixture strategy remains deferred.

### 2026-05-31
- Built and refined `feat-setup-wizard`: DB-free step gating, preserved wizard state, live language switching, preflight checks/auto-heal, driver-aware database input, optional table prefixing, site/default settings, OWNER creation, review/apply, LiveOperation execution, rollback cleanup, and completion marking only after successful apply.
- Hardened setup/security/runtime boundaries: no incidental Doctrine before completion, only DB test/apply as pre-completion DB opt-ins, `/api/live/*` polling allowed, shared password/hash-salt validation, deterministic prefixing, prefixed setup seed/reset behavior, message-backed failure paths, and guarded setup rollback.
- Polished setup/system UX and shell behavior: responsive setup landing, tabular preflight details, compact step navigation, footer/system partials, alert stack, responsive error-shell fixes, and manifest-backed `Studio` branding.
- Improved operational build paths: moved package discovery/translation aggregation out of cache warmers, narrowed service discovery to reduce cold-container pressure, kept `bin/init` under the default memory limit, and cleaned generated package assets/registries out of Git while preserving recovery stubs.
- Closed remaining user-management/setup review follow-ups, updated docs/class map/translations/tests, and kept focused plus full-suite verification green.

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
