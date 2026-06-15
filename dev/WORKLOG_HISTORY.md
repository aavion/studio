# Developer Worklog History

> **Status**: Active  
> **Updated**: 2026-06-15  
> **Owner**: Core  
> **Purpose:** Preserve compacted branch/PR history moved out of `dev/WORKLOG.md` at branch boundaries.

## Usage
Move completed branch or PR logs from `dev/WORKLOG.md` into this file when switching branches or after a PR is merged. Keep the active worklog focused on the current branch so reviewers can see the full PR context while older project history stays available.

## Archived Branches
### 2026-06-15 feat-security-planning
- Created the Security hardening planning package: master branch-tree plan plus detailed `feat-security-*` plans for policy docs, GeoIP observability, abuse foundations, rate enforcement, auto-ban, captcha contracts, IconCaptcha, mailer account delivery, and remember-me.
- Recorded core product decisions for `/api/live/**` rate-limit exclusion, Turbo/browser prefetch classification, scoped `reset()` behavior, database-backed passive signals/auto-bans, Owner recovery protection, GeoIP observability, IconCaptcha asset/accessibility rules, PR-readiness checks, and the inspiration-only `sec-lookup` legacy reference.
- Tightened privacy and architecture guardrails around shared client identity/trusted proxies, injectable clocks, degraded storage, race/idempotency, database-backed security event projection as an open question, and a 30-day maximum for queryable IP-derived data.

### 2026-06-13 to 2026-06-14 feat-symfony-ux-integration
- Added the Symfony UX/UI foundation: namespace-aware Twig components, shared alert stacks, reusable Stimulus/live-polling controllers, notification center behavior, package live endpoints, package-aware cookie consent, local Mercure tooling, and lazy UX integrations.
- Hardened live/API/cookie/Mercure boundaries through repeated review passes, including exact-before-pattern dispatch, reserved live slugs, GET-only package live endpoints, alert topic scoping, consent cookie signing, protected Mercure env handling, URL/link sink validation, and safe fallback polling.
- Added JavaScript behavior coverage and UI/operation overlay refinements while recording follow-ups for public privacy triggers, live endpoint docs/navigation, captcha seed flows, notification preferences, package callbacks, and future LiveComponent filter slices.

### 2026-06-12 to 2026-06-13 docs-cleanup
- Refreshed repository guidance and context docs: moved binding project rules into `AGENTS.md`, updated `.codex` environment/tooling notes, refreshed dependency recap for Symfony 8.1-era packages, and restored branch-oriented worklog archival rules.
- Improved local developer tooling documentation and commands: expanded `bin/lint` with diff/staged/changed modes, Markdown parsing, extensionless PHP checks, Git whitespace handling, route rendering through `php bin/console render:route`, and Symfony UX icon reference checks.
- Recorded Symfony UX integration notes, icon locking behavior, cache warmup/UX Translator expectations, production-only AssetMapper guidance, and ext-sodium platform requirements while archiving obsolete `.codex` helper context.

### 2026-06-07
- Completed the API foundation and hardening slice: stateless Bearer API-key authentication, endpoint definitions/handlers, OpenAPI 3.2 generation, public/private navigation, admin/user/content/package endpoints, CORS, trace headers, feature policy settings, response/error schemas, and Message-layer localized feedback.
- Hardened API access and review boundaries around disabled/setup/maintenance responses, package-owned route patterns, read-only method gates, endpoint permissions, API-key parsing, deleted users, ACL denial status, retained-deleted account mutations, content revisions, package slug identity, pagination/filtering/sorting, and public published-content status leakage.
- Added broad focused coverage and registry checks for API endpoint wiring, access policy, user/self-service API keys, admin mutations, content ACL behavior, package/admin path stability, OpenAPI metadata, CORS, validation details, and hardening review findings while recording remaining package-handler and modularity follow-ups.

### 2026-06-06
- Completed the second project-readiness audit pass: rechecked large classes, namespace placement, hard exceptions, Message-layer usage, dynamic language handling, `studio`/`system` naming, documentation drift, platform notes, and deferred package/security/mail decisions.
- Finished the broad modularity refactor by splitting setup, setup preflight, live operations, backend admin routes, package lifecycle/registry/validation, package ZIP install, scheduler package inspection, ACL group impact cleanup, content item concerns, generated form submission, admin read-model queries, account/profile/token workflows, and public hook descriptors into focused collaborators.
- Hardened security and operational foundations: app-generated request IDs, first-party visitor identity with IP/user-agent fallback bridging, authenticated session visitor binding, central detached process startup, Symfony Lock-backed scheduler/live-operation locking, APP_SECRET-rooted secret payload protection, and idempotent APP_SECRET rotation recovery with private emergency recovery files.
- Migrated technical naming toward branding-neutral conventions: native CSS/template identifiers and CSS variables moved from `studio` to `system`, built-in logs moved to descriptive environment-scoped files, product-prefixed console commands moved to Symfony-style domain commands, and package CSS/template scope validation now enforces package-owned selectors and references.
- Split message code/key catalogues and public event hook descriptors into domain-owned providers while keeping central registries as aggregators, then documented/tested namespace-bound message naming and translation coverage.
- Rechecked docs, drafts, manuals, class map, translations, tests, and CI fallout against the refactored branch; fixed Windows/CI portability issues around sessions, visitor cookies, console output newlines, and package/test cleanup.
- Closed the audit branch with a full local verification pass and recorded remaining follow-ups for package lifecycle journaling, copied-session risk scoring, remember-me, real mail delivery, optional branding-package capability, portable read models, and possible 256M memory guidance.

### 2026-06-05
- Started the broad issue #57 drift audit with a complete production-domain pass across `src/`, large-file inventory, public entry points, child processes, filesystem mutation zones, UUID usage, security boundaries, performance risks, and Symfony-alignment opportunities.
- Ran the decision interview for audit findings F-001 through F-047, recorded product decisions D1 through D47, and aligned feature drafts for architecture, setup, content, system UI, admin, events, packages, security, navigation, API, scheduler, mailer, and operational workflows.
- Established the audit implementation plan and project-rule additions for modularity, public naming, framework proximity, Message-layer diagnostics, dynamic language handling, system-owned technical identifiers, visitor/session identity, cookies, package policy, and future PR drift checks.
- Replaced custom UUID generation with Symfony UID-backed UUIDv7 and centralized UID validation through Symfony's UUID parser while normalizing fixtures and setup/test helpers.
- Added shared runtime foundations for translation catalogue aggregation, setup input normalization, setup seeding, Admin Logs browsing, navigation building, package lifecycle admin views, console workflow result rendering, Twig helper families, package contribution loading, locale preference resolution, response-header hook policy, and dynamic view-injection diagnostics.
- Deferred unresolved boundaries explicitly, including public API response DTOs, custom Twig trust policy, visitor/session hardening completion, and replacement of the debug account-link delivery stub with real Symfony Mailer delivery.
- Completed the PHP CLI resolver branch: added cache-first `APP_DEFAULT_PHP_BINARY` handling, validation, controlled preference refreshes, managed Composer/PHP subprocess execution, Dotenv-aware child environments, and web-request context filtering.
- Hardened setup, scheduler, live-operation, asset, and system-information flows around the shared resolver, including dry-run behavior, translated preflight failures, Tailwind warning handling, supported-locale fallback order, and Admin System Information diagnostics with GD as an explicit requirement and Imagick as optional.
- Expanded and stabilized CI compatibility coverage with Node 24-ready actions, PHP 8.5 lint baseline, PHP 8.4 compatibility jobs for Ubuntu, macOS, Windows, and Linux ARM, plus explicit curl, JSON, XML, and extension-preflight coverage.
- Addressed cross-platform test and runtime issues across Windows, macOS, Linux, and Linux ARM: process streaming in `bin/init`, Composer fallback behavior, detached process startup, SQLite/path assertions, symlink/directory cleanup, null-device usage, and package/operation/translation cleanup helpers.
- Stabilized backend functional tests against full-suite session drift by centralizing reboot-safe synthetic admin logins, refreshing affected scheduler/settings/package sessions, and using Symfony test login helpers for authenticated controller paths.

### 2026-06-04
- Continued `feat-php-cli-resolver`: prefilled the setup site URL from the current HTTP host when no stored wizard value exists; propagated nested asset-rebuild warnings from setup-triggered JSON output into the setup action log, kept direct asset-rebuild text output aware of warning messages, aligned the setup dry-run plan/manual notes with the JSON-backed asset rebuild call, and documented Apache `mod_remoteip` as the preferred reverse-proxy client-IP integration.
- Restored application locale handling after setup by applying the configured default language, session locale, or authenticated user language to main requests; changed profile settings saves to redirect-after-post with the shared alert stack so language changes and success feedback are immediately visible.

### 2026-06-03
- Started `feat-php-cli-resolver`: added a shared PHP CLI resolver for web-hosted setup and background processes, with explicit preflight diagnostics for safe mode, disabled process functions, server-config-blocked CLI, and unresolved PHP binaries; wired setup preflight, setup runner, Composer phar execution, live operations, Messenger drain, scheduler command execution, and asset/backend command queues to use the resolved PHP CLI command prefix, failing command queues with a Message instead of falling back to an unverified `php` binary; aligned Composer preflight/setup checks on project-local Composer environment paths with non-silent Composer output; hardened CLI output assertions against terminal-width wrapping.
- Kept setup subprocess recovery narrow by making Composer diagnostics verbose while isolating the setup-triggered asset rebuild from transient setup secrets and direct database environment values after `dump-env` has persisted the install configuration; added a shared CLI process environment filter so web/CGI request variables are not inherited by Symfony Process children.
- Documented Apache/systemd native-binary hardening for automatic Tailwind rebuilds, removed temporary public process diagnostics, added a non-blocking setup preflight warning for blocked Tailwind native builds, and wrapped Tailwind asset rebuilds so setup/package maintenance can continue while instructing operators to run `php bin/console tailwind:build` through CLI/SSH when the web server policy blocks the binary.

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
