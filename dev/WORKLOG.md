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
- [ ] Security follow-up: define and test production HTTP security-header policy, including CSP, `frame-ancestors`, `Referrer-Policy`, `Permissions-Policy`, `X-Content-Type-Options`, sensitive-route `no-store`, and documented route exceptions.
- [ ] Audit follow-up: decide whether optional branding packages need capabilities beyond `system-template`; package CSS class namespace validation is now enforced for package-owned selectors.
- [ ] Evaluate whether the documented minimum memory requirement should become 256M after PHPUnit 13.2/full-suite runs needed a higher CLI memory limit; do not fix this requirement until setup/init/lint/runtime memory behavior has been reviewed across target hosting platforms.

## Branch Logs
**Usage:** Keep concise session notes in the active worklog and include the current branch in headings, using the form `### YYYY-MM-DD branch-name`. Place new entries chronologically under the matching branch/date heading so reviewers can follow the PR context without reading full verification transcripts. Record meaningful committed or completed changes, decisions, blockers, and follow-ups; keep detailed verification in PR notes unless a result materially affects the worklog context. When switching to a different branch or after a PR is merged, compact the completed branch entry into [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md), then create the new branch entry at the top.

### 2026-06-15 feat-security-geoip-observability
- Started the GeoIP observability branch by compacting the completed `feat-security-policy-docs` notes into `dev/WORKLOG_HISTORY.md`.
- Added a narrow provider-neutral GeoIP resolver foundation so access logs and access statistics keep using normalized `n/a` fallback fields until a real provider returns data.
- Verified the foundation with focused GeoIP/access-log/statistics PHPUnit coverage, PHP syntax checks, container linting, focused linting for changed files, and Git whitespace checks.
- Added the MaxMind GeoIP2 provider slice on top of the foundation: local `.mmdb` lookups via the installed `geoip2/geoip2` dependency, safe provider status, project-relative database path config, sensitive credential preservation/redaction, password-form support for secret fields, and hermetic fake-reader tests without real MaxMind credentials or network access.
- Added the narrow GeoIP2 update foundation: moved the intentionally small GeoIP settings surface to Statistics, changed the default database path to `var/geoip2/GeoLite2-City.mmdb`, derived MaxMind lookup locales from the site default language with `en` fallback, exposed a MaxMind signup help link, added an Admin Operations-backed database download action with non-JS POST fallback, added a daily scheduler callable, and added hermetic updater/scheduler tests that do not use real MaxMind credentials or network access.
- Hardened GeoIP2 download logging: the MaxMind download client now bypasses the autowired Symfony HTTP client service so the license-key query string cannot be captured by HttpClient logging/profiling, and shared log redaction treats `license_key` as sensitive context.
- Aligned first-run setup seeding with the GeoIP2 defaults by explicitly persisting GeoIP disabled, the default `var/geoip2/GeoLite2-City.mmdb` path, and an intentionally empty sensitive MaxMind license-key setting.
- Re-audited the GeoIP observability plan against the implementation, added safe Statistics settings status rendering, and clarified that persistent update-state history and coordinate fields are not planned for this branch.
- Simplified GeoIP status and the branch plan after product review: latitude/longitude and separate persistent GeoIP update-history storage are intentionally not planned because Scheduler run history and live Operation feedback cover update success/failure.
- During PR-readiness review, hardened GeoIP archive extraction by rejecting unsafe TAR member paths before extraction and added direct extractor coverage for safe and unsafe archives.

### 2026-06-16 feat-security-geoip-observability
- Rechecked GeoIP portability and project-rule compliance, tightened Windows drive-letter rejection for configured database paths and TAR member paths, made GeoIP path tests separator-neutral, and reran full PHPUnit, JavaScript, lint, and Git whitespace verification.
- Completed an explicit #57-style PR-readiness pass for the GeoIP slice and hardened downloaded TAR validation by inspecting the compressed archive stream before `PharData` normalization and rejecting symlink, hardlink, and other non-file/non-directory entry types.

### Archived Compacted Branch History
- [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md).
