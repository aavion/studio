# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-05-30
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
- [ ] Finish the visual design-system pass and first release-readiness verification shape in the UI/UX follow-up.
- [ ] Add portable read-model/index strategy when JSON-held values such as localized titles need frequent list-view filtering or sorting across MariaDB/MySQL, SQLite, and PostgreSQL.
- [ ] Before production readiness, review public package/developer-facing class, interface, function, and Twig helper names for clarity and ergonomics; decide whether to rename directly or provide stable aliases so extension APIs read as intentional rather than provisional.

## Session Logs
**Usage:** Create a new log-entry at the top for every coding session roughly describing every change that's being committed.

### 2026-05-30
- Refactored user access into one global account role plus contextual ACL groups with minimum roles; wired Symfony role hierarchy, owner invariants, group assignment validation, setup seeds/migrations, UI, translations, docs, class map, and focused role/group regression coverage.
- Completed account-link hardening across invitation, registration, recovery, security-review, APP_SECRET rotation, API-key, and deleted-account flows, including role-carrying tokens, reactivation guards, stale-token/status checks, structured UI messages, owner delivery retries, and enumeration-safe public registration.
- Added database-near user email/username uniqueness protection with structured message reporting and UI-capable error handling, while token-only onboarding keeps helpful duplicate username feedback.
- Extended `bin/lint` with optional file/directory targets for focused type-based checks and kept project validation, Symfony container checks, Twig/YAML linting, translation comparisons, and Tailwind-aware full-suite behavior aligned.
- Made `bin/lint` release-safe by embedding the translation source catalogue comparison instead of depending on `.codex/` helper scripts.
- Post-checked review readiness by adding Symfony firewall access-control rules, account-flow GET token-type 404s, existing-account invite upgrades without downgrades, profile email validation, and shared ACL helper services for group membership/reactivation handling.
- Updated bin/composer to the latest version (2.10.0) and did a small Update to the root README-file.
### 2026-05-29
- Requested a full audit because of the massive ammount of review findings. Instructions will follow.
- Updated Symfony framework to version 8.1.0 and also some minor dependency updates, fixed an issue within the test suite that was made visible by these updates. No conflicts found.
- Added a small patch to adress container performance by optimizing it's compilation. This won't fix discovered OOM-issues but helps keeping the environment bootable (at least). 
- Found massive performance problems that need further investigation. Review for the `feat-user-management`-branch is on hold.
### 2026-05-28
- Addressed PR review hardening for user management in focused follow-up commits: unified registration settings, explicit security-review POST confirmation, actor-checked/default ACL group handling in registration, reissue, approval, and revocation flows, last-admin/self-closure/security-dispute guards, shared absolute URL generation with generic delivery errors, lowercase email normalization plus valid setup email defaults, DB-free setup username validation, stricter ACL identifiers, login password-reset discovery, password-change delivery preflight, recovery-token reissue, stale dispute-token cleanup, ACL group deletion warnings for content that may become public, stale-token revocation, recovery/deleted-account authorization, revoked-key reveal blocking, stale dispute-delete rejection, retryable secret-rotation recovery links, and defensive state-marker metadata fallback encoding.
- Polished self-service account closure with a dedicated confirmation page, retention-aware `account.closed` notification context, secure logout, and homepage redirect.
- Added retained deleted-user administration with separate listing, configurable retention cleanup, activate/deactivate actions with group-floor repair, account-restored notification, and a reusable cleanup service for later scheduler reuse.
- Hardened deleted-account cleanup for API audit continuity: every non-usable account status change now revokes all non-revoked API keys for the user, deleted-user retention cleanup reassigns retained revoked keys to a stable hidden deleted-user account instead of losing them through cascade deletion, and the API draft records the later revoked-key audit warning-mail follow-up.
- Modularized the large user-management controller surface into focused admin/user/account-flow/API-key controllers plus shared admin list/review helpers, token-maintenance, ACL member, UUID, and LiveLog response services to keep the PR reviewable without changing route names or behavior.
- Completed the user-management foundation: durable account tokens, invitation-first onboarding, disabled/admin-approval/auto-approval registration, profile/password/API-key management, password reset, account-link acceptance, self-service account closure, deleted-account reactivation with UUID preservation and ACL reset, username validation plus default-off username changes, and localized deleted-user identity fallback.
- Hardened token and delivery flows: configurable 24-hour invitation/registration links, fixed one-hour password-reset links, account-link reissue, duplicate pending-token revocation, existing-account registration notices, password-change security-review tokens, expired-token cleanup command, mailer-shaped DEBUG message-log delivery payloads, target-locale routing, and stable account mail-flow keys for the future mailer.
- Completed Admin User and ACL management: searchable/filterable/sortable/paginated account/review/group views, invitation creation, registration approve/reject, account status and group assignment, password-reset link creation, compact review queue, dispute reactivation/delete actions, editable ACL groups, assignable-group filtering, access hierarchy/self-lockout/last-admin guardrails, registered-user `1+` access floors, ACL group impact review, LiveLog-backed group apply, and cleanup of deleted group identifiers from known ACL-bearing records.
- Added supporting platform hardening: centralized `SetupDefaultSeed` for setup/test defaults and initial content/schema seed, translation catalogue cache warmup when source/generated hashes drift, environment-specific `APP_SECRET` fingerprint handling that revokes active API keys and issues owner password-reset links on rotation, reusable state-marker recording for user detail histories, regenerated runtime translations, and updated class map/security/mailer/scheduler documentation.
- Verified the branch with full PHPUnit, focused controller/settings/setup/entity/command/mail/security coverage, backend route coverage, PHP syntax checks, Twig/YAML linting, Symfony container linting, translation catalogue comparison, and diff checks.

### 2026-05-27
- Completed the package/design PR hardening pass: setup lock enforcement, protected-content/static-injection ordering, persisted menu ACL loading, inactive-account login rejection, safe URL rendering, setup password policy, runtime package fault cascades, atomic `package.php` contributions, dependency parse/cycle validation, package removal cascades, installed-version sync, active rediscovery rebuilds, and ZIP namespace validation were all tightened with focused coverage.
- Completed the Operations/ActionLog foundation: token-protected live-operation starts, detached runners, cursor polling below `/api/live/operations/{id}`, retained Admin Operations inspection/detail views, stale cleanup and emergency stale-runner handling, global/atomic runner locks, overlay resume behavior, review-required continuation handoff, and contextual completion actions.
- Completed the staged ZIP install/update boundary: enforced `PACKAGE_SLUG`, cache-staged uploads, manifest validation, review-required apply, overwrite-without-purge handling, downgrade blocks, dependency/reverse-dependent preflight, rollback-safe replacement, post-install discovery, previously-active reactivation, and nullable available-version storage for a future updater.
- Moved package registry refresh into the live-operation path, added the post-response deferred Messenger drain for due `async` jobs, added a `bin/init` pre-install vendor reset, and kept non-JavaScript fallbacks for package/admin actions.
- Completed the log/statistics foundation: MessageLog now writes through Monolog's `studio_message` channel, dedicated rotating `studio_message`/`studio_audit`/`studio_access` files keep 30-day retention, live-operation summaries flow through the message channel instead of a separate operation log, Admin Logs has source selection, filters, pagination, details, and a 24-hour default window.
- Hardened review-reported access/statistics edges: visitor IDs and GeoIP now use Symfony's trusted client IP, statistics aggregate across the full selected window before top-list limits, access query strings plus token-bearing request/referrer path segments redact sensitive values, access/statistics/message telemetry failures cannot break completed responses, empty audit category selections persist as `[]`, and `statistics.enabled` now stops database statistics recording while raw access logs remain active.
- Added audit coverage and policy controls: authentication, password changes, backend maintenance, Operations maintenance, settings saves, package install verification, and lifecycle actions write redacted `studio_audit` entries; Security settings control audit categories, and HTTP-backed audit events include request id, visitor id, requested path, and resolved route for later security automation.
- Added access logging and statistics boundaries: raw access entries include request id, visitor id, route/path, status, timing, surface, host/referrer/language hints, content metadata, response size, proxy/IP hints, user-agent, and GeoIP `n/a` placeholders, while the database-backed `access_statistic_event` model stores anonymized/coarse request facts without raw IPs or user-agents.
- Added the top-level Admin Statistics view with selectable windows (`1h`, `24h`, `7d`, `30d`, `all`), status families, top routes/404s, approximate unique visitors, browser/device/bot summaries, referrer/language/country summaries, DNT counts, average duration, settings for statistics display and DNT policy, and a three-month cleanup cutoff for granular statistic events.
- Exposed request id, visitor id, requested path, and resolved route to Twig error pages for support/debug references, added a replaceable GeoIP resolver boundary, and renamed the consolidated baseline migration to `Version20260527120000` for the current early-development branch state.
- Kept supporting contracts and docs aligned: runtime translation catalogues moved to `translations/runtime`, package/install/lifecycle/logging snippets and drafts were updated, `dev/CLASSMAP.md` stayed current, translations were compared, and the final slice verified with full PHPUnit plus targeted migration/setup, container, schema, syntax, and diff checks.

### 2026-05-26
- Completed the backend/package/admin foundation pass: `/setup`, `/admin`, `/editor`, `/user/*`, account navigation, access-aware backend view registry, Admin Settings forms and sections, package settings storage/metadata, setup redirect, setup web form, setup subprocess environment fallback, logout hardening, and protected-route login callbacks.
- Completed the package contribution and demo pass: shared `public`/`admin`/`editor` view injection surfaces, static and dynamic injections, runtime `package.php` contributions, package setting definitions, demo packages, demo module routes, configurable `/demo` parent, Markdown profile rendering, typography demo, package image/README/source metadata, and package/template lint relaxation.
- Completed the package/theme management pass: package registry overview, package detail metadata, lifecycle review routes, delete/purge/activate/deactivate/reset flows, dependency deactivation cascades, virtual system package metadata/immutability, Theme Management cards, system fallback behavior, backend menu child collapsing, and top-bar action handling on nested routes.
- Completed supporting platform cleanup: language-grouped translation sources with generated runtime catalogues, active package translation aggregation, setup-time `/home` placeholder seed, DBAL baseline migration API update, and verification across targeted backend/package tests plus syntax, Twig, translation, container, and full PHPUnit checks.

### 2026-05-25
- Built the first system UI/design-system slice with tokenized frontend/backend/admin/editor/setup shells, demo preview routes, package-scoped template namespaces, provider fallbacks, CodeMirror provider wiring, and frontend error templates.
- Consolidated package lifecycle foundations: discovery registry persistence, dependency resolution, activation/deactivation, single-active scope handling, fault/reset/remove flows, active package providers, optional `package.php` loading, runtime failure handling, deferred asset rebuilds, and Messenger/cache-warmup discovery triggers.
- Added the public hook/event surface for packages: typed hook descriptors/registry, view/content/render/response events, failure diagnostics, `studio_event_hooks()` metadata, package asset sync observe/extend events, and documented hook boundaries.
- Reworked package assets and translations: scoped asset registry ownership, package asset sync helper splits, JavaScript entry handling, active-package language aggregation, modular source catalogue comparison, and generated default-domain runtime catalogues.
- Refined structured diagnostics and operations logging: message levels, exception/invalid-argument helpers, reporter/logger bridge with redacted `operations.log`, message-backed workflow issues, and clearer WARN/ERROR/SUCCESS/DEBUG semantics across package, content, ACL, setup, and operations flows.
- Kept foundation maintenance current by splitting large seeders/tests/helpers, reorganizing `dev/CLASSMAP.md`, hardening setup/content review findings, and updating drafts, docs, snippets, tests, translations, and worklog references.

### 2026-05-24
- Completed the first-run/setup and deterministic test-data baselines: localized interactive setup, dry-run planning, env persistence, migrations, default settings/admin seeding, password reset, SQLite test bootstrap, demo data, and sequential test-suite protection.
- Completed the first public content delivery slice: published content reads from active revisions, hierarchy/custom/internal `/system` routing, reserved prefixes, home-path config, `401`/`403`/`404` mapping, redirects, redirect-loop protection, localization redirects/fallbacks, variant suffix fallback, and `APP_MAINTENANCE` `503` handling.
- Completed shared security/message foundations: access actors, level-plus-group ACL rules, roleless access-level user bridge, API-key status/encryption metadata, structured message severities, and synchronized English/German catalogues.
- Reworked extensions into one package lifecycle with `PACKAGE_SCOPE`, single-active theme/provider scopes, many-active module scopes, all-or-nothing multi-scope activation, `packages/` discovery, package-owned assets, data-cleanup rules, and native package-scoped view/template foundations.
- Added the package asset pipeline baseline and commands: CSS/JS registry buckets, `assets/packages/` mirroring, Tailwind source discovery, CSS/JS path rewriting, `studio:packages:assets:sync`, `studio:assets:rebuild`, dry-run/progress/JSON output, production compile steps, and structured failure handling.
- Added operational foundations and documentation updates: raw JSON output renderer for `/api/live/**`, ActionLog polling cursor semantics, scheduler draft, external media/file resolver draft, admin/editor route decisions, class maps, and related architecture notes.

### 2026-05-23
- Added the first persistent Core/content database baseline in migration `Version20260527120000`: global config, ACL groups, users, API keys, extension packages, menus, database-backed schemas, schema versions, content items, revisions, and revision-scoped field values.
- Introduced schema/content primitives for status, visibility, slug and route-prefix validation, required `title`/`subtitle` field identifiers, schema sources, nullable active schema/revision pointers, revision diff/import readiness, and level-plus-group ACL override fields.
- Added the shared `Message` model with machine-readable codes, translation keys, parameters, diagnostic context, exception support, synchronized English/German catalogues, and coverage; wired package, manifest, lint, filesystem, process, operation, and content validation flows through it.
- Configured SQLite for the test environment at `var/test/test.db`; the PHPUnit bootstrap now clears `var/test`, applies migrations, and verifies the baseline migration against SQLite.
- Documented pre-`1.0.0` migration/compatibility rules, database portability, filesystem-backed structured logging direction, Markdown/EditorConfig hardbreak rules, schema preset expectations, and portable read-model follow-ups.
- Updated class map, drafts, developer snippets, namespace READMEs, env defaults, and placeholder cleanup to match the new baseline.
- Verified with PHPUnit, translation catalogue sync, YAML syntax, Symfony container linting, full Doctrine schema validation against SQLite, and the EditorConfig audit.

### 2026-05-22
- Prepared the first Core architecture baseline: source namespace boundaries, `bin/init`, `bin/setup`, illustration CSS sync, Apache/nginx/IIS templates, manifest/package discovery, package validation, reusable lint providers, filesystem/integrity helpers, action logs, structured diffs, dry-runs, action queues, operation execution, filesystem/process actions, package operation planning, and structured exports.
- Added supporting docs and fixtures: developer-manual snippet pages for Core/package/import/security/deployment/UI/release/test topics, valid and intentionally invalid package fixtures, visible `TestSuiteLifecycle` notes, and deferred design decisions for SVG theme coloring, dependency maps, and diagnostics models.
- Added and refined automation/test tooling: `.codex` cleanup helpers for ignored artifacts and iCloud/Finder conflicts, shared PHPUnit filesystem helpers, suite-root temporary directories, centralized fixture paths, symlink test helpers, and smoke/negative coverage for fixtures and package preflight diagnostics.
- Hardened the review baseline against traversal, symlink, status-downgrade, Composer metadata, and base-path redirect regressions; updated class map, drafts, documentation, and PHPUnit coverage accordingly.
- Verified by cleaning ignored artifacts, rebuilding with `bin/init`, and re-running PHPUnit, container linting, Composer validation, Markdown link checks, and whitespace checks.

### 2026-05-20
- Created and consolidated the feature-draft roadmap for 0.1.x through 0.5.x plus future features, including core architecture, content modeling, themes, modules, security/ACL, editor workflows, resolver/search, media, import/export, operations, backup/restore, IconCaptcha, release lifecycle, and first-party module candidates.
- Refined the drafts with review decisions for Symfony-first architecture, `.manifest` metadata, public event rules, module/theme lifecycle and rollback, DB-backed admin configuration, ACL visibility, content metadata, language/variant routing, schema Twig, resolver-token planning, import/export normalization, operational action logs, release readiness, and production-oriented validation.
- Captured resolver and security inspiration from the old Grav plugins in `.codex/grav-plugin-inspiration-notes.md`, then translated the useful concepts into drafts without copying old code: lean DB-backed resolver indexes, fieldset assembly support, GeoIP/logging behavior, progressive abuse handling, and modular IconCaptcha.
- Added or expanded supporting documentation: root README project overview, developer manual entry point, draft theme/module developer guidelines, framework documentation cache/recap files, and the draft index release-readiness checklist.
- Reviewed old Symfony/Grav project material as inspiration only and added missing product-surface or future drafts, including system theme/design system, admin/setup UI, media library, draft/publish workflow, frontend delivery/caching, operational admin workflows, navigation/sitemap, diff/review tools, neural-like resolver, and first-party admin add-ons.
- Bundled Composer 2.9.8 as `bin/composer` for future fallback-logic.

### 2026-05-20
- Cleaned-up repository for better readability and versioning-/branch-handling.
- Minor text-only fixes.

### 2026-05-19
- Removed Symfony skeleton frontend demo code from the main asset entrypoint and deleted the example Stimulus controller.
- Moved ApexCharts and CodeMirror into dedicated Stimulus controllers with lazy lifecycle handling.
- Added basic documentation templates and guidelines.

### 2026-05-15
- Initialized git repository for future use
