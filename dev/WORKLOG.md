# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-06-21  
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
  - Open: extension signature/checksum strategy; direct vs staged updates; rollback scope.

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
- [ ] Before production readiness, review public extension/developer-facing class, interface, function, and Twig helper names for clarity and ergonomics; decide whether to rename directly or provide stable aliases so extension APIs read as intentional rather than provisional.
- [ ] Audit follow-up: add a durable extension lifecycle operation journal/coordinator for multi-step activation, deactivation, install, rollback, and cleanup flows.
- [ ] Audit follow-up: design copied-session plus copied-visitor-cookie risk scoring in the Security branch; current hard session binding now records visitor changes as high-risk signals, but still does not detect complete cookie-pair duplication.
- [ ] Audit follow-up: implement remember-me with Symfony-style persistent server-side tokens, visitor binding, explicit revocation, token rotation, and audit signals in the Security branch.
- [ ] Audit follow-up: replace the debug account-link mail/message-log delivery stub with the real Mailer delivery contract and a dedicated Mail Message/API catalogue.
- [ ] Security follow-up: define and test production HTTP security-header policy, including CSP, `frame-ancestors`, `Referrer-Policy`, `Permissions-Policy`, `X-Content-Type-Options`, sensitive-route `no-store`, and documented route exceptions.
- [ ] Frontend-delivery follow-up: change custom system error-page rendering so `/system/error-pages/{status}` resolves a content entity for the inner error-page body/fieldset, then lets the status-specific error template decide the full page chrome. The current renderer sends custom error entities through the normal frontend content entity template, which is too rigid for lightweight `400`/`429` responses versus full `404` pages.
- [ ] Security/Admin ACL follow-up: add explicit Owner/configurable ACL gates for security-signal visibility/mutation, IP-bearing access-log projection visibility, related exports, cleanup operations, and future signal review actions across Admin UI, Admin API, Operations, and service boundaries.
- [ ] Audit follow-up: split the remaining large Admin ACL-adjacent controllers and API handlers along route/action boundaries when those domains are touched next; this slice already extracted the new matrix/form construction, while broader splits for `BackendController`, Admin user/ACL/extension controllers, extension APIs, operation/scheduler APIs, and the pre-existing large user ACL/review API handlers would be safer as a dedicated behavior-stable refactor.
- [ ] Editor/Content/Config follow-up: warn non-blockingly when a proposed route or slug would match a configured suspicious probe path, so legitimate content remains possible but accidental high-signal probe namespace collisions are visible before publication.
- [ ] Captcha/rate-limit follow-up: add a short-lived opaque 429 recovery context when real captcha challenges are wired, so verified provider-backed solves can reset only the whitelisted/resettable descriptor and subject scope that produced the rendered 429 without exposing bucket IDs, subject keys, IP data, or limiter internals.
- [ ] Aggregation/rate-limit follow-up: evaluate short-lived emergency country/continent traffic-shedding buckets for DDoS-like spikes. Treat this as aggregate rate limiting, not auto-ban or geo-blocking; ignore `n/a` GeoIP, keep thresholds extreme, preserve trusted-user recovery and Owner/API access, and use brief windows such as 5-15 minutes.
- [ ] Audit follow-up: decide whether optional branding extensions need capabilities beyond `system-template`; extension CSS class namespace validation is now enforced for extension-owned selectors.
- [ ] Extension database follow-up: design safe update handling for existing extension-owned table definitions after extension updates, including table/column/index/FK drift detection, explicit diagnostics, and a versioned update or migration-like operation instead of silently mutating existing tables during activation.
- [ ] Extension asset follow-up: design a Node dependency asset pipeline for extension-local `assets/node_modules` payloads, including dependency validation and safe mirroring rules for executable or otherwise unsafe dependency files without breaking legitimate frontend packages.
- [ ] Extension reference follow-up: design stable lookup/reference interfaces for core-owned users, ACL groups, content items, and content schema entities so extensions can persist portable IDs in their own tables without DB-level foreign keys that could block core deletions or couple core lifecycle semantics to extension tables. Lookup APIs must return explicit missing/deleted fallbacks, for example a deleted-user display label, so extension logic can handle unavailable core data without broken references.
- [ ] Extension API follow-up: before public extension API release, add a documented handler context or safe facade so extension API handlers can use controlled domain services instead of direct low-level Doctrine/DBAL access.
- [ ] Extension runtime follow-up: before enabling broader extension-owned services, routes, event subscribers, or Messenger handlers, define the active-extension gate, ownership attribution, validator policy, fault handling, and reviewable contribution interfaces for each surface.
- [ ] Evaluate whether the documented minimum memory requirement should become 256M after PHPUnit 13.2/full-suite runs needed a higher CLI memory limit; do not fix this requirement until setup/init/lint/runtime memory behavior has been reviewed across target hosting platforms.

## Branch Logs
**Usage:** Keep concise session notes in the active worklog and include the current branch in headings, using the form `### YYYY-MM-DD branch-name`. Place the newest branch/date heading directly below `## Branch Logs`; within a matching branch/date heading, add new notes at the top so the newest context stays first. Record meaningful committed or completed changes, decisions, blockers, and follow-ups; keep detailed verification in PR notes unless a result materially affects the worklog context. When switching to a different branch or after a PR is merged, compact the completed branch entry into [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md), then create the new branch entry at the top.

### 2026-06-21 feat-security-captcha-contract
- Added typed runtime boot contributions with runtime context delivery, loader-level phase partitioning, and staged registry rollback so boot failures do not leave partial runtime contributions.
- Added active extension PSR-4 class loading for validated `EXTENSION_NAMESPACE` classes below extension-owned `src/`, with duplicate namespace rejection and no automatic extension-local vendor autoload registration.
- Removed ambiguous naked callable execution from runtime and activation `extension.php` loading so extension code must use typed contribution factories for callable contribution phases.
- Added typed extension contribution factory contracts and a shared contribution context, wired runtime factories through staged runtime contribution expansion, kept activation contribution reads from executing runtime factories, and covered dynamic runtime expansion plus activation-factory rejection without partial runtime state.
- Aligned adjacent extension, event, security, and IconCaptcha drafts with the sharpened captcha-contract runtime policy and provider-selection decisions.
- Sharpened the captcha-contract implementation plan against the current codebase with coding-agent guardrails for callable phase separation, staged contribution registration, extension-owned validator policy, provider Twig scope validation, extension event dispatch adapters, curated extension-facing event names, Symfony lifecycle adapter candidates, and review checkpoints.

### 2026-06-20 feat-security-captcha-contract
- Fixed CI follow-ups by keeping the demo module focused on its configurable public info page plus Markdown typography child route and moving detached-process inherited descriptor cleanup into the detached child shell with `/proc/self/fd` coverage on Linux.
- Proactively hardened adjacent extension review boundaries by tokenizing Twig template references, blocking dynamic PHP callable-expression invokes, and binding extension API/live endpoint definition owners to their extension slugs.
- Fixed Cloud Review findings for extension database table cleanup after mid-create failures, unbounded filesystem extension validation before recursive asset mirroring, non-module content-schema identifier collisions, and post-commit extension asset sync completion-hook failures.
- Fixed Cloud Review findings in runtime extension contributions and database DDL by requiring extension-owned view templates for each surface, rejecting raw extension column options before DBAL schema creation, and pinning hyphenated scheduler identifiers as valid in the current scheduler grammar.
- Fixed Cloud Review findings in static extension validation by blocking string-literal PHP callables, raw request superglobals, and cross-scope Twig template references inside expression functions.
- Fixed Cloud Review findings for flat-root extension ZIP installs, activation-plan single-active conflicts, portable extension database identifier limits, and bounded extension manifest/dependency version syntax.
- Gated destructive optional MySQL/MariaDB extension database integration tests behind explicit `MYSQL_TEST_ACTIVE=1` opt-in so the default PHPUnit suite cannot clear a reachable local `studio_test` database.
- Centralized reusable identifier validation in `IdentifierSpec`, including 60-character owner/content slug families, dot-path identifiers, snake-case identifiers, handler keys, scheduler-style machine identifiers, database prefixes, ACL group identifiers, and canonical UUIDs.
- Fixed Cloud Review findings for extension slug validation and cleanup ordering by capping extension slugs to the storage limit, rejecting digit-prefixed slugs consistently, aligning CSS/API/live namespace validation with the manifest slug contract, and purging extension database tables before content schemas.
- Anchored the project-local review, review-fix, and PR-readiness Codex skills in `AGENTS.md` as the preferred workflow helpers when their scoped triggers apply.
- Hardened the local review and review-fix Codex skills with compact practices adapted from the internal security workflows: explicit worklist closure, sibling-instance inspection, counterevidence checks, source/control/sink framing, bounded reproduction, and proof that the original path is closed after fixes.
- Fixed local-review findings for extension lifecycle cleanup and scheduler contribution boundaries by snapshotting/restoring settings and ACL cleanup around destructive purge steps, requiring extension-owned scheduler identifiers and callable/action-queue targets, and scoping scheduler runtime providers to their owning extension.
- Fixed follow-up review findings for active ZIP overwrite content restoration, foreign-owner setting contributions, purge cleanup ordering, activation rollback diagnostics, fault-persistence reporting, `.yaml` inventory alignment, and stale demo-extension documentation.
- Fixed Mercure test-lifecycle cleanup and added an explicit detached-process option for persistent services to close inherited file descriptors before detaching, while preserving default lock inheritance for operation and scheduler process chains.
- Fixed review findings for extension removal rollback content restoration, failed content-schema contribution staging cleanup, and full-depth ZIP payload policy validation before install.
- Added extension dependency version constraints with bare compatibility requirements, short-version floors for `>=` minimum requirements, and precision-pinned `=` requirements.
- Added an optional auto-skipping MySQL/MariaDB integration test fixture for extension database DDL cleanup, documented the local `studio_test` DSN/user setup, and kept the default PHPUnit lifecycle on SQLite.
- Fixed extension-contract review findings for collision-free extension-owned table/schema identifiers, API-scope asset registry bucketing, activation contribution reloading of already-active dependencies, explicit created-table cleanup on contribution failure, and content-status restoration when lifecycle rollback follows automatic schema-content archival.
- Kept extension-owned tests discoverable by adding `extensions/**/tests` discovery to PHPUnit and moving the demo module controller test into the demo extension tree so future extension submodules can carry their own host-integration coverage.
- Split oversized extension/OpenAPI contract collaborators into focused contribution expansion/guarding, endpoint/view/scheduler storage, extension database naming/reference/order helpers, and OpenAPI component/tag factories without changing runtime behavior.
- Fixed review findings by keeping early `bin/init` Composer platform checks aligned with `--no-dev` bootstrap installs, restricting extension language catalogues to documented `*.yaml` files, and configuring English as the Symfony translator fallback.
- Limited extension database foreign keys to extension-owned tables, with PK/unique reference validation and explicit follow-ups for safe update drift handling plus stable core-entity lookup/reference interfaces.

### 2026-06-19 feat-security-captcha-contract
- Added the `api` extension scope, gated extension API endpoint/handler contributions behind it, exposed typed manifest variables through `ExtensionSettings::get($extension, 'manifest.{key}')` with persisted setting overrides, switched operation/command lifecycle asset rebuilds to synchronous execution, and changed content-schema purge to force-archive affected content while retaining still-referenced disabled schema copies with warning context.
- Implemented extension content-schema lifecycle impact handling for deactivation reviews and automatic archival of public content tied to deactivated extension schema presets.
- Documented content-schema lifecycle impact policy: deactivation should archive/unpublish directly affected public content, while automatic republish on extension reactivation is explicitly out of scope without a future confirmed restore journal.
- Clarified extension CSS namespace validation so owner-wide selectors and matching scope selectors are accepted while foreign rendered-area scope selectors stay blocked.
- Added scope-gated extension database and content-schema contribution contracts, with activation-time database/content-schema synchronization, purge cleanup, tests, and updated extension/template documentation.
- Relaxed and clarified extension validation boundaries for development metadata, private assets, extension-local dependency payloads, open `EXTENSION_*` manifest descriptors, and ZIP installer copy filtering.
- Added early Git submodule synchronization to `bin/init` so clean checkouts initialize extension submodules before Composer installs dependencies.

### 2026-06-18 feat-security-captcha-contract
- Started the captcha contract branch after `feat-security-auto-ban` merged and compacted the completed auto-ban branch notes into `dev/WORKLOG_HISTORY.md`.
- Reviewed the captcha-contract plan, IconCaptcha handoff plan, and Security policy defaults. Expected branch scope is the generic provider contract, resolver, workflow/provider configuration, global form integration, safe validation/result model, and verified-provider success/failure hooks without shipping a concrete IconCaptcha provider.
- Added Noto Color Emoji utility CSS generated from the local font and Unicode emoji-test data, aligned the Noto `@font-face` descriptors with the local font metadata, and imported the emoji utilities into the main stylesheet.
- Updated the local Tabler icon webfont assets and generated utility class map to `@tabler/icons-webfont` 3.44.0.
- Curated a draft IconCaptcha challenge asset index under `.codex/tmp/challenge-assets` pairing 100 Noto Emoji SVGs with Tabler filled SVG icons, including category and confusable-family selection constraints.

### Archived Compacted Branch History
- [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md).
