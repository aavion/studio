# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-06-15  
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
- [ ] Audit follow-up: design copied-session plus copied-visitor-cookie risk scoring in the Security branch; current hard session binding intentionally covers visitor changes, not complete cookie-pair duplication.
- [ ] Audit follow-up: implement remember-me with Symfony-style persistent server-side tokens, visitor binding, explicit revocation, token rotation, and audit signals in the Security branch.
- [ ] Audit follow-up: replace the debug account-link mail/message-log delivery stub with the real Mailer delivery contract and a dedicated Mail Message/API catalogue.
- [ ] Audit follow-up: decide whether optional branding packages need capabilities beyond `system-template`; package CSS class namespace validation is now enforced for package-owned selectors.
- [ ] Evaluate whether the documented minimum memory requirement should become 256M after PHPUnit 13.2/full-suite runs needed a higher CLI memory limit; do not fix this requirement until setup/init/lint/runtime memory behavior has been reviewed across target hosting platforms.

## Branch Logs
**Usage:** Keep concise session notes in the active worklog and include the current branch in headings, using the form `### YYYY-MM-DD branch-name`. Place new entries chronologically under the matching branch/date heading so reviewers can follow the PR context without reading full verification transcripts. Record meaningful committed or completed changes, decisions, blockers, and follow-ups; keep detailed verification in PR notes unless a result materially affects the worklog context. When switching to a different branch or after a PR is merged, compact the completed branch entry into [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md), then create the new branch entry at the top.

### 2026-06-15 feat-security-policy-docs
- Added `dev/draft/security-hardening/policy-defaults.md` as the central first-implementation source for Security hardening TTLs, rate-limit thresholds, auto-ban defaults, captcha defaults, privacy ceilings, logging projection posture, and configuration rules.
- Linked policy defaults from the master Security hardening plan, the Security/API/Contact-Mail-Logging drafts, and the affected branch detail plans so later implementation branches can cite one policy reference.
- Compacted the completed `feat-security-planning` worklog entry into `dev/WORKLOG_HISTORY.md` so the active worklog stays focused on the policy-docs branch.
- Raised the first IconCaptcha challenge TTL default to 15 minutes for realistic form completion time while keeping one-shot validation, scoped failure buckets, context binding, refresh abuse signals, and answer-leak checks as required bot-protection controls.
- Split the website global rate-limit default into deliberate burst and sustained buckets, with Turbo/browser prefetch tracked through a separate lower-confidence observation path so speculative requests do not drain user-facing navigation budgets.
- Adjusted scheduler and probe policies: scheduler trigger limits now support minutely cron, high-signal probes are limited to one per 10 minutes with generic `400` handling, probe paths are configurable with broad defaults, auto-ban defaults to on, and active Admin/Owner recovery protections are explicit.
- Documented recovery login bypass policy using the normal login route plus a bypass flag, guarded by a dedicated 2/minute and 10/hour bucket with 30-minute retry behavior and no bypass of CSRF, credential checks, login-failure accounting, or audit logging.
- Clarified captcha auto-success policy: provider `none`, missing providers, and disabled providers keep workflows graceful but never reset/refill rate-limit buckets, clear bans, or satisfy captcha-based `429` recovery.
- Added cross-cutting Security policy decisions for deterministic enforcement order, block-response semantics, probe-pattern validation, configuration bounds, and auditable Owner/Admin exemptions.
- Added a first configuration-surface matrix that separates fixed policy, code/config defaults, protected secrets, bounded Admin settings, and later-tunable thresholds for follow-up Security branches.

### Archived Compacted Branch History
- [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md).
