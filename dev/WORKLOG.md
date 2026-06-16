# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-06-16  
> **Owner**: Core  
> **Purpose:** Keeps track of changes and upcoming tasks. 

**Important:** Create a log entry for every commit, describing what's been done and tracking to-dos and follow-up tasks.  
**ALWAYS KEEP UP-TO-DATE!**

## Roadmap
**Usage:** Use as guidance on what major changes to implement next. Keep the list up-to-date while proceeding.

- [x] **0.1.x Foundation**

- [ ] **0.2.x Security and extension baseline**
  - [ ] Admin interface
  - [x] Setup UI

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
  - [x] API layer
  - [ ] Frontend delivery and caching
  - [ ] Operational admin workflows
  - [x] Scheduler
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

- ! Keep roadmap sub-items aligned with feature drafts when implementation changes scope, order, or dependencies. Last reviewed: 2026-06-06.
- ! Before the first stable `1.0.0` release, keep Doctrine migrations consolidated into one current baseline migration.
- ! Prefer repository/database queries over full-table PHP filtering for lists, pagination, ACL impact checks, and other scalable read paths.
- [ ] Keep database-prefix coverage hardened by keeping Doctrine metadata self-checked against `TablePrefix::TABLES`, validating prefixed ORM metadata, and covering raw DBAL insert/update/join/delete prefix rewriting.
- ! Keep Symfony service discovery narrow so DTOs, value objects, messages, events, enums, and other non-services do not bloat the container.
- [ ] Finish the visual design-system pass and first release-readiness verification shape in the UI/UX follow-up.
- [ ] Add portable read-model/index strategy when JSON-held values such as localized titles need frequent list-view filtering or sorting across MariaDB/MySQL, SQLite, and PostgreSQL.
- [ ] Editor/API follow-up: when the final content/editor model lands, replace provisional API content list filtering with a domain-owned actor-aware content list/read resolver covering canonical paths, language, variants, optional version selection, pagination, filtering, and sorting.
- [ ] Before production readiness, review public package/developer-facing class, interface, function, and Twig helper names for clarity and ergonomics; decide whether to rename directly or provide stable aliases so extension APIs read as intentional rather than provisional.
- [ ] Audit follow-up: add a durable package lifecycle operation journal/coordinator for multi-step activation, deactivation, install, rollback, and cleanup flows.
- [ ] Audit follow-up: design copied-session plus copied-visitor-cookie risk scoring in the Security branch; current hard session binding now records visitor changes as high-risk signals, but still does not detect complete cookie-pair duplication.
- [ ] Audit follow-up: implement remember-me with Symfony-style persistent server-side tokens, visitor binding, explicit revocation, token rotation, and audit signals in the Security branch.
- [ ] Audit follow-up: replace the debug account-link mail/message-log delivery stub with the real Mailer delivery contract and a dedicated Mail Message/API catalogue.
- [ ] Security follow-up: define and test production HTTP security-header policy, including CSP, `frame-ancestors`, `Referrer-Policy`, `Permissions-Policy`, `X-Content-Type-Options`, sensitive-route `no-store`, and documented route exceptions.
- [ ] Editor/Content/Config follow-up: warn non-blockingly when a proposed route or slug would match a configured suspicious probe path, so legitimate content remains possible but accidental high-signal probe namespace collisions are visible before publication.
- [ ] Audit follow-up: decide whether optional branding packages need capabilities beyond `system-template`; package CSS class namespace validation is now enforced for package-owned selectors.
- [ ] Evaluate whether the documented minimum memory requirement should become 256M after PHPUnit 13.2/full-suite runs needed a higher CLI memory limit; do not fix this requirement until setup/init/lint/runtime memory behavior has been reviewed across target hosting platforms.

## Branch Logs
**Usage:** Keep concise session notes in the active worklog and include the current branch in headings, using the form `### YYYY-MM-DD branch-name`. Place new entries chronologically under the matching branch/date heading so reviewers can follow the PR context without reading full verification transcripts. Record meaningful committed or completed changes, decisions, blockers, and follow-ups; keep detailed verification in PR notes unless a result materially affects the worklog context. When switching to a different branch or after a PR is merged, compact the completed branch entry into [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md), then create the new branch entry at the top.

### 2026-06-16 feat-security-abuse-foundation
- Started the Abuse Foundation branch by compacting the completed GeoIP observability notes into `dev/WORKLOG_HISTORY.md` and refreshing the Security hardening drafts/project rules for the next implementation slice.
- Expanded the slice to include parallel database log projections for message, audit, and access logs while retaining the 30-day rotating file logs as the raw fallback. Added policy-bounded retention settings/defaults, `security_signal_event` passive signal storage, DB-backed Admin/API log browsing with UUID detail links, source tabs, broad hidden-field search, and source-specific filters where `DEBUG`/`INFO` are hidden by default only for level-aware sources.
- Updated the Security hardening master plan, Abuse Foundation detail plan, Logging draft, policy defaults, class map, translations, migration baseline, and setup/default settings coverage for the new logging projection and passive-signal scope.
- Follow-up for `feat-security-admin-acl-enforcement`: add explicit Owner/ACL gates for security-signal visibility/mutation, IP-bearing access-log projection visibility, related exports, cleanup operations, and future signal review actions instead of relying only on broad Admin Logs access.
- Scope guard: trusted proxy handling stays in deployment/webserver configuration; Abuse Foundation uses Symfony's resolved request client IP for Security identity, may use raw forwarding headers only as untrusted Visitor-ID differentiation entropy, and keeps IP-ban thresholds laxer than Visitor-ID thresholds to reduce shared/untrusted-network false positives.
- Follow-up for Editor/Content: show a non-blocking warning when a content route or slug would match a configured suspicious probe path so editors can avoid accidental honeypot/probe namespace collisions.
- Implemented the Visitor-ID entropy half of that policy by mixing normalized forwarding-header candidates into cookie-less fallback visitor hashes only; Security identity, GeoIP, ban keys, and signal evidence still use Symfony's resolved client IP rather than raw proxy headers.
- Added the passive Abuse Foundation facade, subject resolver, request-intent classifier, suspicious-probe matcher, and symbolic action-cost catalogue. These expose visitor/user/API/IP-bucket subjects, `/api/live/**`, prefetch, CORS preflight, scheduler/setup/admin/API intents, and cost metadata for later rate/ban branches without enforcing limits yet.
- Added best-effort passive signal recording for high-signal probes and unsafe prefetch attempts. Signals carry Visitor-ID plus IP-bucket HMAC context when available and never store raw proxy-header values.
- Made suspicious probe path patterns configurable as an editable line-based Security setting with CSV-tolerant parsing, protected high-signal defaults, invalid-pattern fallback, setup seed coverage, translations, and focused matcher tests.
- Added high-risk passive security-signal recording for enforced session/visitor mismatches while preserving the existing forced logout and audit behavior. Complete copied-session plus copied-visitor-cookie risk scoring remains a later Security/remember-me follow-up.
- Clarified the rate-enforcement handoff for HTTP security headers: rate/recovery/error responses own tested `no-store`, while the full CSP/frame/referrer/permissions/header policy remains a dedicated response-hardening/frontend-delivery follow-up if still deferred.
- Switched passive security-signal expiry and cleanup to Symfony Clock so retention behavior is deterministic in tests and matches the Abuse Foundation time-boundary plan.
- Hardened PR-readiness findings before final checks: database log projection retention now uses Symfony Clock, and the Admin Logs OpenAPI enum documents the database-backed sources including `security_signal`.
- Reintroduced the Symfony `application` log as an explicit file-backed Admin/API source while keeping message, audit, access, and security-signal browsing database-backed; application detail links use the existing synthetic file-line hash IDs because Symfony Monolog lines do not carry database UUIDs.
- Final verification: `bin/phpunit` passed with 1339 tests and 8649 assertions; `bin/jstest` passed with 37 tests; `bin/lint` passed all checks including container, Twig, translation keys, Tailwind, Markdown, and Git whitespace. `git diff --check feat-security...HEAD` only reports intentional Markdown metadata hardbreaks that project lint accepts.

### Archived Compacted Branch History
- [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md).
