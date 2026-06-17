# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-06-17  
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

### 2026-06-17 feat-security-rate-enforcement
- Started the rate-enforcement slice after `feat-security-admin-acl-enforcement` merged, archived the completed Admin ACL branch notes into `dev/WORKLOG_HISTORY.md`, and refreshed the active worklog for the new branch.
- Updated `composer.lock` after dependency resolution refreshed `guzzlehttp/psr7` to 2.12.0 and `justinrainbow/json-schema` to 6.10.0.
- Recorded rate-enforcement product decisions: exact `/user/login?bypass=1` recovery path, fail-open limiter-storage degradation, one Owner-gated rate-limit mode setting with `off`/`standard`/`strict`/`panic`, and a dedicated rate-limit policy catalogue that keeps bucket budgets/profile scaling separate from semantic action costs.
- Added the rate-limit policy catalogue, profile scaling, Owner-gated Security setting, descriptor-backed Symfony limiter facade, request subscriber, redacted HTML/JSON `429`/probe `400` responses, fail-open diagnostics, Owner ordinary exemption, authenticated-user multiplier, `/api/live/**`/prefetch exclusions, login-success reset, and the dormant verified-provider captcha reset interface.
- Added a test-environment opt-in header for the request subscriber so legacy functional tests that mutate Security settings or share synthetic visitors are not affected by global limiter state; production and development enforcement are unchanged.
- Verification: focused syntax/lint checks for rate-limit, settings, message, translation, response, docs, and worklog files; focused PHPUnit for rate-limit catalogue/enforcer/reset, Security settings registry/form/API/UI, message catalogues, and HTTP enforcement responses; `php bin/console lint:container --env=test --no-debug`; `php bin/console render:route /admin/settings/security --role=owner --env=test --no-debug --include-status`; `bin/lint --diff`; full `php bin/phpunit` passed with 1424 tests and 9326 assertions.
- Hardened review-sensitive rate enforcement details: `/cron/run` is no longer Owner-exempt and now uses explicit scheduler intervals (`standard` 1/minute, `strict` 1/15 minutes, `panic` 1/hour), rate-policy descriptors are generated from user-visible action counts plus unique action-cost multipliers with a single-action profile floor, limiter degradation diagnostics report through the Message layer, and shared rendered HTTP error pages set `no-store` centrally.
- Verification: focused PHPUnit for action-cost catalogue, rate-limit catalogue/enforcer/reset, public error pages, rate-limit response controllers, and message catalogues; full `php bin/phpunit` passed with 1431 tests and 9422 assertions.
- Adjusted suspicious-probe profile scaling so strict/panic extend the probe window while the single-action credit floor prevents Symfony limiter consume failures below the probe action cost.
- Extended `render:route` with request-header input and response-header output, then added CLI route-render coverage proving repeated `/cron/run` renders with Owner context and mutable Owner API key receive scheduler `429` with `Retry-After` and `no-store` instead of bypassing through the Owner exemption.
- Added a production-environment guard to `render:route` so the debug renderer fails closed in `APP_ENV=prod` and remains available only for development/test diagnostics.
- Clarified the scheduler rate-limit policy documentation: `/cron/run` uses an operational pre-auth interval guard, and legitimate scheduler `429` responses in strict/panic modes are not treated as abuse or security signals.
- Addressed first Cloud Review rate-limit findings: auth workflow buckets now charge only unsafe submissions, the limiter runs after routing but before Symfony authentication failures, `/build/**` is excluded with generated assets, active-profile descriptors are used for login/captcha resets, and `/user/login?bypass=1` is wired to the dedicated recovery-login buckets.
- Hardened adjacent route-guard coverage so content routes cannot claim technical/static namespaces such as `/assets/**`, `/build/**`, `/_profiler/**`, `/profiler/**`, and `/_wdt/**` while relying on limiter exclusions.
- Addressed follow-up Cloud Review bypass findings: suspicious probes now run before ordinary `/api/live/**` exclusions, failed login/API credentials charge through `LoginFailureEvent`, ordinary buckets run after Symfony authentication so Owner/API-key subjects and authenticated multipliers are available, login/registration/password-reset buckets include HMAC-redacted submitted-account subjects, invalid API prefixes no longer become primary API limiter subjects, and high-impact Admin upload/download paths are classified before broad package/admin buckets.
- Verification: focused syntax checks, focused PHPUnit coverage for request classification, rate-limit enforcer/reset/controller behavior, render-route cron handling, content route guards, and test seed isolation; `php bin/console lint:container --env=test --no-debug`; `bin/lint --diff`; full `php bin/phpunit` passed with 1458 tests and 9679 assertions.
- Addressed additional Cloud Review hardening findings: authentication-failure rate checks now include Admin API mutation/upload/download families, successful login resets the same submitted-account/visitor/IP login keys used by enforcement, persisted Symfony limiter IDs include the active descriptor shape so profile changes do not reuse stale fixed-window state, and consume operations use Symfony's configured lock factory.
- Verification: PHP syntax checks for changed rate-limit classes/tests; focused PHPUnit for limiter factory, reset, controller enforcement, enforcer, and request classification coverage passed with 75 tests and 594 assertions; `php bin/console lint:container --env=test --no-debug`; `bin/lint --diff`; `git diff --check`; full `php bin/phpunit` passed with 1462 tests and 9717 assertions.
- Addressed the next Cloud Review rate-limit bypass findings: Bearer-bearing `OPTIONS` requests now classify as API authentication attempts instead of anonymous CORS preflights, recovery-login `GET` renders spend the dedicated recovery bucket while avoiding website buckets, Admin API auth failures add IP anchoring, read-only Owner API-key write denials spend the write/admin bucket before the 403 while read-write Owner keys stay ordinary-exempt, and scheduler intervals key on HMAC-redacted submitted scheduler credentials with IP fallback/secondary anchoring.
- Verification: PHP syntax checks for changed abuse/rate-limit classes and tests; focused PHPUnit for request classification, subject resolution, rate-limit enforcer/controller enforcement, scheduler controller, and read-only API method coverage passed with 100 tests and 698 assertions; `php bin/console lint:container --env=test --no-debug`; `bin/lint --diff`; full `php bin/phpunit` passed with 1473 tests and 9820 assertions.
- Tightened recovery-login accounting so only `GET /user/login?bypass=1` uses the recovery render bucket; unsafe bypass submissions remain normal login attempts, and Panic mode explicitly keeps one recovery render plus the first login submission within budget.
- Narrowed ordinary rate-limit technical path exclusions to exact path segments so generated/static prefixes such as `/_profiler` and `/_wdt` do not accidentally cover similarly named public routes.
- Added the missing `admin.settings.*` source/runtime translations for Security settings fields and options touched by this branch so the Owner-gated Security settings page renders localized Captcha, rate-limit, audit, signal-retention, and probe-pattern controls instead of raw translation keys.
- Verification: PHP syntax checks for changed request-classifier/rate-limit classes and tests; focused PHPUnit for request classification, rate-limit policy/request-subscriber/enforcer/controller behavior, content route guards, and settings coverage passed with 163 tests and 1364 assertions; `php bin/console lint:container --env=test --no-debug`; `bin/lint --diff`; `php bin/console render:route /admin/settings/security --role=owner --env=test --no-debug --include-status`; `php bin/console render:route '/user/login?bypass=1' --env=test --no-debug --include-status --header 'X-Rate-Limit-Testing: 1'`; full `php bin/phpunit` passed with 1489 tests and 9847 assertions.
- Addressed the latest Cloud Review hardening findings: sensitive recovery-login and Admin export/download/diagnostic `GET` requests now classify before spoofable prefetch forgiveness, derived profile floors now keep two costed ordinary actions available while preserving explicit single-action scheduler/probe interval policies, and suspicious-probe blocking runs before API availability, setup redirect, maintenance, live/API exclusion, and ordinary technical path gates.
- Verification: PHP syntax checks for changed rate-limit/abuse classes and tests; `git diff --check`; focused PHPUnit for request classification, rate-limit policy/request-subscriber/enforcer/reset/factory behavior, and controller enforcement passed with 116 tests and 909 assertions; `php bin/console lint:container --env=test --no-debug`; `bin/lint --diff`; `php bin/console debug:event-dispatcher kernel.request --env=test --no-debug` confirmed probe priority 900 before response-producing gates; full `php bin/phpunit` passed with 1496 tests and 9941 assertions.
- Documented the future cache panic-mode direction in the frontend delivery/caching draft: Security `panic` is the intended coordination point for a bounded TTL lock that can serve anonymous public traffic from safe cache entries during DDoS-like events while preserving auth, ACL, probe blocking, audit, and Owner/Admin recovery behavior.

### Archived Compacted Branch History
- [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md).
