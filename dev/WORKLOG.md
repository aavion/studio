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
- [ ] Security/Admin ACL follow-up: add explicit Owner/configurable ACL gates for security-signal visibility/mutation, IP-bearing access-log projection visibility, related exports, cleanup operations, and future signal review actions across Admin UI, Admin API, Operations, and service boundaries.
- [ ] Editor/Content/Config follow-up: warn non-blockingly when a proposed route or slug would match a configured suspicious probe path, so legitimate content remains possible but accidental high-signal probe namespace collisions are visible before publication.
- [ ] Audit follow-up: decide whether optional branding packages need capabilities beyond `system-template`; package CSS class namespace validation is now enforced for package-owned selectors.
- [ ] Evaluate whether the documented minimum memory requirement should become 256M after PHPUnit 13.2/full-suite runs needed a higher CLI memory limit; do not fix this requirement until setup/init/lint/runtime memory behavior has been reviewed across target hosting platforms.

## Branch Logs
**Usage:** Keep concise session notes in the active worklog and include the current branch in headings, using the form `### YYYY-MM-DD branch-name`. Place new entries chronologically under the matching branch/date heading so reviewers can follow the PR context without reading full verification transcripts. Record meaningful committed or completed changes, decisions, blockers, and follow-ups; keep detailed verification in PR notes unless a result materially affects the worklog context. When switching to a different branch or after a PR is merged, compact the completed branch entry into [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md), then create the new branch entry at the top.

### 2026-06-16 feat-security-admin-acl-enforcement
- Started the Admin ACL enforcement slice from `feat-security-admin-acl-enforcement`, reviewed the branch implementation plan and Security ACL draft, and archived the completed Abuse Foundation worklog into `dev/WORKLOG_HISTORY.md` for a clean branch basis.
- Product direction recorded for the implementation baseline: feature/action permissions are grouped by surface (`admin`, `editor`, `frontend`); Admin ACL granularity delegates selected denied/visible/mutable permissions through seeded Owner-controlled overrides; explicit ACL-group states can grant or restrict relative to role/default state after the relevant surface gate is satisfied; non-configurable rules remain visible read-only for transparency.
- Added a lightweight domain-provider Admin ACL registry with denied/visible/mutable states, Admin/Editor/Frontend surfaces inferred from key prefixes, seeded configurable defaults under `acl.admin.features`, Owner override persistence, explicit ACL-group override states, and an Owner-gated `Settings/ACL` matrix.
- Wired `access_feature` metadata into protected settings fields, Admin backend views/navigation, GeoIP update and maintenance backend action rendering/execution, package/theme UI actions, package install/lifecycle controllers, dynamic package settings pages, and package lifecycle Admin API review/confirmation so Live Operations remain generic while sensitive callers enforce the thematic feature key.
- Registered the initial configurable Admin-surface features for security/logging/statistics/API/scheduler/package settings, logs, packages/themes, operations, maintenance actions, scheduler operations, users, user ACLs, and user reviews; backup/restore, package self-update, security settings, and support rows remain non-configurable transparency rows where required.
- Package settings ACL rows are registered dynamically for active packages with settings. Inactive package rows stay hidden without losing stored overrides, and package purge removes the matching `admin.settings.packages.{package_slug}` override from `acl.admin.features`.
- Cached Admin ACL registry definitions, configured overrides, and ACL-group availability with the same short-TTL Symfony cache shape used by suspicious-probe patterns, plus explicit invalidation on matrix saves, ACL-group changes, and package lifecycle/registry changes; the cache draft records that this must be re-evaluated once the unified cache strategy exists.
- Added Admin ACL enforcement to direct user, ACL-group, invitation/review, operations, scheduler, logs, statistics, theme, backup, and Admin API entry points so visible-only features keep read/review models available but reject confirmed mutations. Existing buttons remain rendered disabled where the UI layout expects them.
- Treated Audit and Security Signal log sources as sensitive read surfaces that require mutable `admin.logs` access; visible-only log access keeps normal log sources available and filters sensitive sources from browser/API source lists.
- Extended `Settings/ACL` audit context with redacted old/new changed-feature summaries while keeping internal helper keys out of `setting_keys`. `admin.packages.self_update` remains a non-configurable transparency row because no separate self-update mutation route exists in this slice.
- Fixed Setup review registration-mode labels to use Setup-owned translation keys instead of depending on Admin settings labels during unauthenticated setup rendering.
- Updated translations, runtime catalogues, drafts, class map, and focused tests for Owner-default Package Lifecycle ACL behavior and the new `Settings/ACL` view.
- Verification: `php -l` on changed PHP entry points/tests; `bin/lint` for changed templates/translations/CSS; `php bin/console lint:container`; `php bin/phpunit tests/Core/AdminAcl/AdminFeatureAccessPolicyTest.php`; `php bin/phpunit tests/Controller/ApiPackageControllerTest.php`; `php bin/phpunit tests/Controller/BackendControllerTest.php --filter 'Package|SettingsRoutes|AclSettings|AdminRegisteredBackendViewRoute'`; `php bin/phpunit tests/Controller/ApiSettingsControllerTest.php tests/Core/Config/SettingsApiReadModelTest.php tests/Core/Config/CoreSettingsFormHandlerTest.php`; `php bin/phpunit tests/Controller/ApiUserControllerTest.php --filter 'FeatureReadOnly'`; `php bin/phpunit tests/Controller/ApiAdminOperationalControllerTest.php --filter 'LogsFeatureReadOnly|FeatureReadOnly'`; `php bin/phpunit tests/Controller/AdminUserControllerTest.php --filter 'FeatureReadOnly'`; `php bin/phpunit tests/Controller/BackendControllerTest.php --filter 'LogsFeatureReadOnly|FeatureReadOnly|AclSettingsMatrix'`; `php bin/phpunit tests/Controller/BackendControllerTest.php --filter 'testSetupRouteWalksToReviewWithoutAuthentication'`; `php bin/phpunit tests/Controller/ApiAdminOperationalControllerTest.php tests/Controller/ApiUserControllerTest.php tests/Controller/AdminUserControllerTest.php tests/Controller/BackendControllerTest.php`; `bin/lint --diff`.

### Archived Compacted Branch History
- [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md).
