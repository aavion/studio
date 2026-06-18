# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-06-18  
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
- [ ] Frontend-delivery follow-up: change custom system error-page rendering so `/system/error-pages/{status}` resolves a content entity for the inner error-page body/fieldset, then lets the status-specific error template decide the full page chrome. The current renderer sends custom error entities through the normal frontend content entity template, which is too rigid for lightweight `400`/`429` responses versus full `404` pages.
- [ ] Security/Admin ACL follow-up: add explicit Owner/configurable ACL gates for security-signal visibility/mutation, IP-bearing access-log projection visibility, related exports, cleanup operations, and future signal review actions across Admin UI, Admin API, Operations, and service boundaries.
- [ ] Audit follow-up: split the remaining large Admin ACL-adjacent controllers and API handlers along route/action boundaries when those domains are touched next; this slice already extracted the new matrix/form construction, while broader splits for `BackendController`, Admin user/ACL/package controllers, package APIs, operation/scheduler APIs, and the pre-existing large user ACL/review API handlers would be safer as a dedicated behavior-stable refactor.
- [ ] Editor/Content/Config follow-up: warn non-blockingly when a proposed route or slug would match a configured suspicious probe path, so legitimate content remains possible but accidental high-signal probe namespace collisions are visible before publication.
- [ ] Captcha/rate-limit follow-up: add a short-lived opaque 429 recovery context when real captcha challenges are wired, so verified provider-backed solves can reset only the whitelisted/resettable descriptor and subject scope that produced the rendered 429 without exposing bucket IDs, subject keys, IP data, or limiter internals.
- [ ] Audit follow-up: decide whether optional branding packages need capabilities beyond `system-template`; package CSS class namespace validation is now enforced for package-owned selectors.
- [ ] Evaluate whether the documented minimum memory requirement should become 256M after PHPUnit 13.2/full-suite runs needed a higher CLI memory limit; do not fix this requirement until setup/init/lint/runtime memory behavior has been reviewed across target hosting platforms.

## Branch Logs
**Usage:** Keep concise session notes in the active worklog and include the current branch in headings, using the form `### YYYY-MM-DD branch-name`. Place new entries chronologically under the matching branch/date heading so reviewers can follow the PR context without reading full verification transcripts. Record meaningful committed or completed changes, decisions, blockers, and follow-ups; keep detailed verification in PR notes unless a result materially affects the worklog context. When switching to a different branch or after a PR is merged, compact the completed branch entry into [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md), then create the new branch entry at the top.

### 2026-06-18 feat-security-auto-ban
- Started the auto-ban preparation branch after `feat-security-rate-enforcement` merged and archived the completed rate-enforcement branch notes into `dev/WORKLOG_HISTORY.md`.
- Recorded the auto-ban response decision: active temporary bans use the shared browser error renderer's forced bare response path with `403 Forbidden`, `Retry-After` when the TTL is known, `Cache-Control: no-store`, the safe Request ID, and the generic bare context `Request blocked due to suspicious activity. retry-after: <seconds>` without exposing score, rule, subject, IP, or signal internals.
- Updated the auto-ban plan and Security policy defaults for the score-based implementation: suspicious `400`/`403`/`404`/`429` signals feed a one-hour global score, Visitor ID is primary, stable IP scoring is secondary through a laxer threshold multiplier, active bans use cache-flock TTL state, TTLs escalate `1h`/`3h`/`24h`/`7d`, persistent ban-trigger and reset `security_signal_event` records drive escalation and reset cutoffs, threshold changes affect only future ban decisions, trusted registered users default to level `6`/`MANAGER` and are never auto-banned, setup/database degradation fails open, and the resolver-matched `/user/login?bypass=1` recovery-login render path remains reachable.
- Clarified the first score defaults and enforcement ordering: Visitor threshold `100`, IP threshold `x2`, minimum two qualifying signals, error-hit weight `7`, probe/session-copy weight `100`, failed-auth weight `10`; API keys are trusted-user context rather than auto-ban subjects, and active Visitor/IP bans must resolve before error pages or rate-limit bucket consumption but after trusted-user/API-key context can bypass them.
- Tightened review-readiness decisions: scoreable signals are persisted per evaluated source subject so IP scoring uses indexed subject reads instead of JSON context, one evaluation creates at most one active ban with Visitor preferred over IP, active-ban list rendering uses a cache-backed index while per-subject TTL state remains authoritative, and first-slice auto-ban settings/manual resets are Owner-gated.
- Recorded the auto-ban performance policy: score aggregation is triggered only after a scoreable `security_signal_event` write and reuses that DB path for indexed Visitor/IP lookups; ordinary non-signal requests perform only the active-ban cache check and must not start database score queries.
- Verification: documentation-only preparation slice; focused Markdown lint passed.
- Implemented the first auto-ban slice: scoreable Security signals for suspicious error hits, probes, failed-auth attempts, and session/visitor mismatches now feed Visitor/IP subject scoring only from the signal write path; active bans use cache-backed TTL state with an Admin index, Visitor-before-IP selection, reset cutoffs, retained trigger/reset signal context, and early request enforcement after trusted context resolution.
- Added Owner-gated Security settings for enablement, trusted-user level, and score threshold; Security settings link to a dedicated active-ban list instead of embedding the list in the settings registry, and the link renders disabled when auto-ban is disabled. Disabling auto-ban now stops score evaluation and enforcement while existing TTL states remain until expiry.
- Kept recovery-login bypass matching on the established `/user/login?bypass=1` user-workflow route and RequestPathResolver coverage for locale-prefixed `/user/login` path matching without adding a plural `/users` runtime surface.
- Normalized current-time reads in the auto-ban/session-security path through Symfony Clock and disabled kernel-triggered auto-ban evaluation/enforcement by default in the Symfony `test` environment to prevent broad controller suites from poisoning shared active-ban cache state with intentional error-response tests.
- Replaced throw-based auto-ban payload timestamp parsing with bounded `createFromFormat()` parsing and routed auto-ban storage/evaluation degradation through Security Message-layer diagnostics while preserving fail-open behavior.
- Added configurable hidden Owner alerts for newly decided bans, linked alert actions directly to the active-ban list, added success/error alerts for manual ban release and failed settings saves, and routed alert-delivery degradation through Security Message-layer diagnostics.
- Registered `/api/v1/admin/security/auto-bans` list/detail/reset endpoints through the existing API endpoint registry. Browser and API auto-ban review/reset surfaces use the existing non-configurable `admin.settings.security` ACL gate, so delegated non-Owner admins cannot access the ban list.
- Review-hardened active-ban enforcement so `/api/live/**` remains outside ordinary rate-limit `429` handling but no longer bypasses an already active auto-ban, and added a `request_id`/`reason_code` Security-signal index for the Visitor-over-IP ban dedupe query.
- Verification: `php -l` on changed PHP entry points passed; `php bin/console lint:container` passed; focused AutoBan/API/settings/message PHPUnit groups passed; full `php bin/phpunit` passed with 1631 tests and 10708 assertions; `bin/lint --diff` plus explicit lint for new auto-ban files passed.
- Addressed first Cloud Review round with separate reviewable commits: recovery login submissions can authenticate despite source bans; active-ban index updates are serialized and roll back unindexed active state; failed cache deletes make reset fail; reset success requires reset-signal persistence; concurrent evaluators emit trigger signals/Owner alerts only for newly created bans; detail views are newest-first while retaining history; auto-ban `403` responses do not create passive signals; and auto-ban API endpoints now advertise Owner-level access before the handler ACL gate.
- Addressed second Cloud Review round with separate reviewable commits: index-write rollback now verifies active-state removal and falls back to an expired payload when cache deletion fails; shared ignorable static/tooling/well-known path matching prevents routine missing favicon/robots/touch-icon/discovery requests from creating passive `404` Security signals; login ban bypass now requires the CSRF-backed recovery marker rendered by `GET /user/login?bypass=1`; and auto-ban enablement fails open when config storage is unavailable while setup still seeds completed installations as enabled.

### Archived Compacted Branch History
- [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md).
