# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-06-22  
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
  - [ ] Branding extensions
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
- [ ] Branding extension follow-up: implement the dedicated `branding` scope from `dev/draft/future-branding-extensions.md` as a future isolated slice; current `system-template` remains an additive capability for identity-bearing extensions, not a branding package model.
- [ ] Extension database follow-up: design an explicit destructive cleanup or migration contract for extension-owned database tables, including post-migration table drops, destructive column/index changes, data-copy review, and operator-facing diagnostics.
- [ ] Extension mail follow-up: finalize configurable extension mail workflow contributions with owner-prefixed workflow IDs, localized default templates, parameter metadata for the template editor legend, active-extension filtering, purge cleanup, and a real delivery bridge once the mailer contract exists. The current `extension_mail()` facade is only a development-stub boundary.
- [ ] Extension ZIP/archive upload follow-up: design dedicated extension-owned archive inspection/import support. `ZipArchive` remains blocked for extension PHP, and runtime upload import currently rejects ZIP/archive payloads by extension and MIME until archive validation, size accounting, and nested payload policy are defined.
- [ ] Extension asset follow-up: design a Node dependency asset pipeline for extension-local `assets/node_modules` payloads, including dependency validation and safe mirroring rules for executable or otherwise unsafe dependency files without breaking legitimate frontend packages.
- [ ] Extension reference follow-up: design stable lookup/reference interfaces for core-owned users, ACL groups, content items, and content schema entities so extensions can persist portable IDs in their own tables without DB-level foreign keys that could block core deletions or couple core lifecycle semantics to extension tables. Lookup APIs must return explicit missing/deleted fallbacks, for example a deleted-user display label, so extension logic can handle unavailable core data without broken references.
- [ ] Extension API follow-up: before public extension API release, add a documented handler context or safe facade so extension API handlers can use controlled domain services instead of direct low-level Doctrine/DBAL access.
- [ ] Extension runtime follow-up: before enabling broader extension-owned services, routes, event subscribers, or Messenger handlers, define the active-extension gate, ownership attribution, validator policy, fault handling, and reviewable contribution interfaces for each surface.
- [ ] Extension runtime audit follow-up: split the remaining large extension-runtime coordination classes (`ExtensionRuntimeContributionRegistry`, `ExtensionRuntime`, `ExtensionRuntimeContributionGuard`, `ExtensionPhpLoader`, `ExtensionReferenceFacade`, and `extension_functions.php`) after the contract surface stabilizes; they are cohesive enough for this branch but above the preferred line-count target.
- [ ] Operation UI follow-up: migrate existing persisted live-operation labels to a translation-backed label-key/parameter model or a dedicated operation-label catalogue; this branch fixed the newly added extension-operation label, while older labels remain literal operational strings.
- [ ] Evaluate whether the documented minimum memory requirement should become 256M after PHPUnit 13.2/full-suite runs needed a higher CLI memory limit; do not fix this requirement until setup/init/lint/runtime memory behavior has been reviewed across target hosting platforms.

## Branch Logs
**Usage:** Keep concise session notes in the active worklog and include the current branch in headings, using the form `### YYYY-MM-DD branch-name`. Place the newest branch/date heading directly below `## Branch Logs`; within a matching branch/date heading, add new notes at the top so the newest context stays first. Record meaningful committed or completed changes, decisions, blockers, and follow-ups; keep detailed verification in PR notes unless a result materially affects the worklog context. When switching to a different branch or after a PR is merged, compact the completed branch entry into [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md), then create the new branch entry at the top.

### 2026-06-22 feat-security-captcha-contract
- Fixed local-review findings around extension runtime loading and scheduler safety by dependency-ordering active extension PHP loads, committing staged runtime contributions before boot with rollback on boot failure, preserving listener failure ownership for lifecycle faulting, rejecting extension-owned raw scheduler command tasks while keeping owner-prefixed callable/action-queue targets, and redacting scheduler run context before history/API exposure.
- Audited production `catch (Throwable)` and `throw new` usage against the message-layer rule, kept hard invariants and low-level adapter failures in place, normalized API JSON parse failures to stable reason codes, and removed an unnecessary hard-coded maintenance-mode exception message.
- Simplified live-operation label localization by folding translated label handling into `LiveOperationStarter::start()` with tolerant fallback for existing literal or stored continuation labels.

### 2026-06-21 feat-security-captcha-contract
- Fixed PR-readiness drift found during the second audit pass by replacing newly added hard-coded contributor-facing exception texts with MessageException/MessageKey diagnostics, adding translated live-operation labels for direct operation starts, translating the empty extension-alert fallback, and updating the PR-readiness skill to emit copyable PR notes.
- Fixed a PR-readiness class-map drift issue by replacing misleading generated extension-runtime descriptions with a neutral runtime-surface description, keeping the map usable as a lookup during review.
- Enforced extension manifest identity scopes so real extensions must declare `module`, a theme scope, or a provider scope before adding capability scopes such as `system-template`, `api`, `database`, `content-schema`, `scheduler-tasks`, or `operations`; documented `module` as the neutral identity fallback rather than a capability gate.
- Added the future branding extension draft and roadmap entry for a later exclusive single-active `branding` package model instead of mixing branding semantics into the current scope slice.
- Added visible `scheduler-tasks` and `operations` extension scopes for high-risk scheduled callable/action-queue execution and detached operation workflows, and enforced those scopes for scheduler task/provider and operation definition/provider contributions.
- Extended `extension_content_get()` and optional `extension_content_query(..., ['include_fields' => true])` read models with active revision metadata and bounded schema-field sets for stored language/variant contexts, while keeping the helpers read-only and actor-visible.
- Added contribution-based extension operations with `ExtensionOperationDefinition`, extension-owned ActionQueue providers, the shared `extension.operation` live-operation provider, scheduler reuse for registered extension action queues, and live Admin detail buttons for active extension operation targets.
- Added `extension_trans()` for caller-owned `ext.<extension-slug>.*` translation keys and tightened extension log/alert translation-key handling so only caller-owned extension keys pass through while core or foreign extension keys use the existing system fallback.
- Added `extension_cookie_get()`, `extension_cookie_set()`, and `extension_cookie_delete()` for caller-owned cookies registered through extension `CookieConsentDefinition` contributions, with contribution owner tracking, optional-cookie consent checks, registered identity preservation, and response-queued writes.
- Added `extension_csrf_token()` and `extension_csrf_valid()` for extension-scoped Symfony CSRF intents using `extension:{slug}:{intent}`, including optional current-request token extraction from `_csrf_token`, `csrf_token`, or `_token`.
- Added `extension_can()` for caller-owned current-actor role/min-access, ACL-group membership, and content view/edit/manage checks without accepting caller-supplied actor identity or returning entity data.
- Added `extension_content_query()` and `extension_content_get()` for caller-owned access to published public content read models visible to the current actor, with field payloads available only through bounded active-revision read models and without Doctrine entities, write models, or caller-controlled owner slugs.
- Added `extension_request()` for caller-owned runtime access to a redacted current-request snapshot with method/path/route/locale plus bounded query/body/header/cookie/file metadata, while preserving the PHP policy block on raw request superglobals and avoiding raw cookie values, upload contents, and upload temporary paths.
- Added `extension_upload_store()` for ordinary Symfony `UploadedFile` request uploads into caller-owned durable storage, with traversal, size, invalid-upload, executable/server-side/script/SVG/archive extension and MIME rejection, and non-extension caller safe defaults. Live endpoints remain non-upload targets; future form/post hooks should pass selected request upload objects into extension code without unblocking `$_FILES`.
- Added `extension_storage_put()`, `extension_storage_get()`, `extension_storage_delete()`, `extension_storage_exists()`, and `extension_storage_list()` for durable caller-owned storage below `var/extensions/{APP_ENV}/{extension}/storage`, with traversal, symlink, reserved metadata path, size, TTL metadata, list-limit, and cross-extension isolation coverage.
- Added a placeholder `extension_mail()` runtime facade that validates caller-owned workflow IDs, recipients, and scalar/stringable parameters before logging a mailer-shaped development-stub message; final extension mail workflow contributions and real delivery remain a documented follow-up.
- Added additive extension database table update handling for existing contributed tables: nullable/defaulted columns and new indexes may be applied, while column drops/changes, index drops/renames, required columns without defaults, and foreign-key changes are rejected with explicit diagnostics.
- Added `extension_db_fetch()`, `extension_db_insert()`, `extension_db_update()`, and `extension_db_delete()` for bounded caller-owned CRUD access to extension-contributed database tables with local table/column identifier validation, parameterized values, fetch limits, and criteria-required mutations.
- Added `extension_file_get()` for bounded read-only access below the calling extension directory, preserving traversal, absolute-path, symlink, directory, missing-file, and oversized-file rejection as the safe replacement for direct `file_get_contents()`.
- Extended extension reference lookups so user references include roles/groups and visible ACL-group references include redacted group members without exposing credentials, profiles, settings, or raw Doctrine entities; role alert topics are documented as minimum-access-level topics.
- Added `extension_lookup()` and `extension_entity()` for actor-visible, redacted content/user/ACL-group/role/extension references without exposing Doctrine entities, credentials, raw user profile/settings, extension paths, or extension metadata.
- Added `extension_alert()` and expanded UI-alert topics to support HMAC-backed role and ACL-group topics, with current user role/group subscriptions and graceful `false` handling for invalid or unresolvable extension alert targets.
- Added `extension_log()` for caller-attributed extension runtime logs, with invalid literal messages routed through a system-owned fallback message key and extension-supplied context nested to avoid top-level context spoofing.
- Added `extension_http_request()` as a bounded service-backed extension HTTP facade with method/scheme validation, JSON result decoding, response/payload size limits, and private-network blocking by default behind a core config key.
- Added `extension_live_url()` and `extension_api_url()` helpers that build caller-owned extension endpoint URLs through the shared runtime boundary and Symfony routes while rejecting unsafe relative endpoint inputs.
- Added the `extension_asset_url()` runtime helper for caller-owned mirrored public assets while keeping private extension assets non-addressable through URLs.
- Added the `extension_asset()` runtime helper for bounded reads from the calling extension's own `assets/` or `private-assets/` tree with traversal, symlink, directory, missing-file, and oversized-file rejection.
- Added the `extension_settings_get()` runtime helper so extension PHP can read its own extension settings through the shared caller-attributed runtime boundary without receiving a caller-controlled extension slug.
- Started the broader extension runtime facade implementation by replacing the cache-only static runtime bridge with a shared `ExtensionRuntime`/`ExtensionRuntimeServices` boundary that still derives the calling extension from the file path before delegating cache helpers.
- Added a service-backed extension cache facade with global runtime helpers for extension-owned TTL artifacts, slug-scoped cache keys, invalid caller/key rejection, delete support, and portable payload limits for future captcha provider challenge state.
- Fixed review findings by isolating catchable extension public-hook listener failures from later extension listeners, preserving safe recovery-login return targets across captcha failure retries, rejecting overlapping active extension class namespaces, and resolving extension-local vendor PSR-4 prefixes by specificity.
- Captured IconCaptcha readiness boundary: private assets, GD, extension-owned live endpoints, and extension-owned cache artifacts are available for future challenge rendering and validation.
- Removed obsolete native captcha fallback payload metadata so the no-provider fallback renders no `captcha[...]` fields; rendered forms keep only the authoritative `_captcha_instance` marker, and the root component now uses the documented `policy` attribute without legacy `option`/`options` aliases.
- Added the public `require_vendor('vendor/package')` extension PHP facade so active extension code can opt into one shipped Composer package by PSR-4 metadata without automatic extension `vendor/autoload.php`, Composer `files`, scripts, or non-extension package probing.
- Added provider-backed captcha challenge failures as low-to-moderate security signals for recoverable or suspicious provider validation failures and visitor mismatches against existing captcha instances as separate low-risk signals, while leaving missing instances, cache misses, skipped results, provider faults, and invalid provider returns unscored; ordinary form buckets remain the submit-rate limiter for this slice.
- Added minimal captcha provider diagnostics for contract-level provider failures only: runtime exceptions and invalid provider return values now create message-log entries with safe provider/phase/form context, while ordinary captcha challenge outcomes remain unlogged to avoid noise.
- Moved captcha submit trust to the Captcha domain by registering one-hour visitor-bound `_captcha_instance` artifacts when the root field renders, consuming instances on mutating requests, stripping spoofed client captcha results, writing server-owned `failed`/`skipped`/`verified` results, and letting form handlers choose `require` versus `require-verified` acceptance.
- Added a recovery-login captcha request gate so valid auto-ban recovery submissions validate required captcha before Symfony FormLogin can authenticate, while ordinary login posts and invalid recovery-token posts stay on the existing security paths.
- Added a required captcha form validator for server-selected captcha forms so direct POSTs cannot bypass an active provider by omitting the field or submitting the native fallback payload; wired it into public registration while no-provider fallback remains non-blocking.
- Corrected captcha form placement to an opt-in root Twig component contract: ordinary login and token-protected account setup stay captcha-free, auto-ban recovery login and public email-only registration render the field, and future submit validation should depend on field presence.
- Changed the native captcha provider fallback to an invisible no-challenge field, added stable form ids for provider JS targeting, passed dynamic form ids into captcha field rendering, and covered that client-supplied fallback/skip payloads cannot bypass an active provider.
- Removed the obsolete `security.captcha.provider` core setting, source translations, and stale Admin/API/settings tests so captcha provider selection is lifecycle-owned by the active `captcha-provider` extension.
- Added provider-neutral captcha bridge contracts and fallback/fault result handling that resolves active `captcha-provider` contributions from the runtime provider registry without adding provider-specific assets or challenge logic.
- Added generic runtime provider contributions with `captchaProvider()` builder support, provider-scope guards, duplicate-provider rejection, and staged rollback coverage.
- Added runtime extension event-listener contributions backed by the public hook registry, stable listener priority ordering, and `PublicEventDispatcher` integration with structured extension-owned failure diagnostics.
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
