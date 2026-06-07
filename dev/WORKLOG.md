# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-06-07  
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

- ! Keep roadmap sub-items aligned with feature drafts when implementation changes scope, order, or dependencies. Last reviewed: 2026-06-06.
- ! Before the first stable `1.0.0` release, keep Doctrine migrations consolidated into one current baseline migration.
- ! Prefer repository/database queries over full-table PHP filtering for lists, pagination, ACL impact checks, and other scalable read paths.
- [ ] Keep database-prefix coverage hardened by keeping Doctrine metadata self-checked against `TablePrefix::TABLES`, validating prefixed ORM metadata, and covering raw DBAL insert/update/join/delete prefix rewriting.
- ! Keep Symfony service discovery narrow so DTOs, value objects, messages, events, enums, and other non-services do not bloat the container.
- [ ] Finish the visual design-system pass and first release-readiness verification shape in the UI/UX follow-up.
- [ ] Add portable read-model/index strategy when JSON-held values such as localized titles need frequent list-view filtering or sorting across MariaDB/MySQL, SQLite, and PostgreSQL.
- [ ] Editor/API follow-up: when the final content/editor model lands, replace provisional API content list filtering with a domain-owned actor-aware content list/read resolver covering canonical paths, language, variants, optional version selection, pagination, filtering, and sorting.
- [ ] Before production readiness, review public package/developer-facing class, interface, function, and Twig helper names for clarity and ergonomics; decide whether to rename directly or provide stable aliases so extension APIs read as intentional rather than provisional.
- [x] API branch planning: before implementation, turn `dev/draft/0.4.x-ApiLayer.md` into a concrete endpoint/resource plan covering initial read/write scope, API-key method gating, response DTOs, error envelope, pagination, filtering, sorting, audit signals, and tests.
- [ ] Audit follow-up: add a durable package lifecycle operation journal/coordinator for multi-step activation, deactivation, install, rollback, and cleanup flows.
- [ ] Audit follow-up: design copied-session plus copied-visitor-cookie risk scoring in the Security branch; current hard session binding intentionally covers visitor changes, not complete cookie-pair duplication.
- [ ] Audit follow-up: implement remember-me with Symfony-style persistent server-side tokens, visitor binding, explicit revocation, token rotation, and audit signals in the Security branch.
- [ ] Audit follow-up: replace the debug account-link mail/message-log delivery stub with the real Mailer delivery contract and a dedicated Mail Message/API catalogue.
- [ ] Audit follow-up: decide whether optional branding packages need capabilities beyond `system-template`; package CSS class namespace validation is now enforced for package-owned selectors.
- [ ] Evaluate whether the documented minimum memory requirement should become 256M after PHPUnit 13.2/full-suite runs needed a higher CLI memory limit; do not fix this requirement until setup/init/lint/runtime memory behavior has been reviewed across target hosting platforms.

## Session Logs
**Usage:** Create a new log entry at the top for every coding session roughly describing every committed change. At the start of each new feature branch, compact previous session logs by session, move them to [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md), and keep the archived history linked below the current session log.

### 2026-06-07
- Switched generated OpenAPI documents to OpenAPI 3.2.0, derived API metadata plus Bearer realm from the root `.manifest` through the existing `SystemPackageMetadataProvider`, and started using 3.2 document features including `$self`, named servers, `info.summary`, license metadata, and hierarchical tag metadata.
- Split OpenAPI tags by shell/domain scope so backend administration uses `backend-admin-*`, backend editor resources use `backend-editor-*`, frontend content uses `frontend-content-*`, package navigation stays independent from admin package management, and future package contributions can use `packages-{package_slug}-*` without colliding with system resources.
- Hardened package API contribution validation so package endpoints must also declare package-scoped `packages-{package_slug}-*` OpenAPI tags.
- Standardized collision-free dynamic Admin API paths so API child resources use `items/` while backend UI routes use readable `details/`; added executable user review token API actions for registration approval, pending token reissue, and token denial/revocation through the existing `AdminUserInvitationWorkflow`, all requiring `?confirm=true`.
- Added deeper Admin API mutation coverage for the current domain layers: operation responses now include status/continue/confirm links, settings sections can be patched through the existing settings form handler, user detail resources support one-role/multi-group updates through username routes, ACL groups support identifier-based create/detail/edit/delete with review/confirmation and optional LiveOperation execution, user/group membership can be added or removed through relationship endpoints, user security-review actions expose confirm-first reactivation/denial flows, scheduler tasks expose detail/history, patch, and run-now endpoints, and operation maintenance actions require confirmation before cleanup/lock/runner changes.
- Deepened the Admin API slice with Hypermedia-style `/api/v1/admin` navigation, sectioned settings endpoints, source-specific log endpoints with existing UI filters, live-operation detail and continuation review endpoints, package detail read models, and review-first package lifecycle actions that require `?confirm=true` before starting the existing LiveOperation runner. Added the shared admin operation-start resource shape, API conflict messaging, OpenAPI definitions, and focused API coverage.
- Planned the `feat-api` implementation scope: REST/OpenAPI first, stateless API-key authentication, read-only method gating, domain-owned ACL enforcement, canonical content slug-hierarchy identity, page/limit pagination, Message-layer API feedback, admin endpoint namespaces under `/api/v1/admin/...`, and package endpoint definition namespaces under `/api/v1/packages/{package_slug}/...`.
- Started the API foundation with a stateless `/api/v1` firewall, Bearer API-key authenticator, request-scoped API context, read-only method gate, shared JSON responder, endpoint provider/definition registry, dynamic OpenAPI JSON generation from registered definitions, and only system metadata endpoints for status and documentation.
- Refined API foundation access so endpoint definitions are private by default but can opt into anonymous safe-method reads with `allow_public`; invalid Bearer keys still fail authentication, and missing keys only receive an anonymous context for explicitly public read endpoints.
- Added deterministic `/api/v1` availability handling so incomplete setup and Doctrine/DBAL failures return Message-layer JSON `503` responses with `Retry-After` instead of setup redirects, HTML error pages, or uncaught exception output.
- Added API-specific maintenance handling after Bearer authentication so public/non-admin API requests return JSON `503` during maintenance while admin API keys can still access `/api/v1`.
- Broadened the global maintenance bypass to `/api/**` so internal `/api/live/**` operation polling remains available during `APP_MAINTENANCE`; `/api/v1/**` remains protected by the API-specific maintenance gate.
- Added central definition-backed API dispatch through `ApiEndpointController`, endpoint handler registration, package API endpoint/handler contributions constrained to `/api/v1/packages/{package_slug}/...`, and first admin-only read endpoints under `/api/v1/admin` for endpoint discovery, settings, package overview, and user lists.
- Added explicit Hypermedia-style package API navigation at `/api/v1/packages`, backed by a reusable endpoint navigation builder that lists visible direct child paths and methods without replacing the OpenAPI contract.
- Added the second API foundation endpoint baseline with admin read endpoints for themes, scheduler, backups, operations, logs, statistics, user groups, and user reviews, plus content navigation, ACL-aware published content item metadata, and author-level schema metadata including custom Twig while deferring mutations and deep editor/content workflows.
- Shared the existing content read ACL policy between public content resolution and API content item lists so role-or-group view rules and additional group restrictions stay domain-owned and covered by API functional tests.
- Hardened API endpoint registration with a registry wiring test that fails on missing definition-backed handlers, duplicate method/path pairs, or duplicate OpenAPI operation IDs.
- Added deterministic content item detail paths with standardized child (`items`), variant (`variants`), and revision (`revisions`) navigation, plus stable `501` content mutation command stubs for create, edit, delete, publish/unpublish, revision publish/unpublish, and variant create/edit/delete until the Editor/Content domain services land.
- Reserved content collection query parameters, revision detail reads, and validate/diff command stubs so the Editor/Content slice can add optimistic locking, automatic pre-persist validation, structured OK/WARN/FAIL feedback, and missing-reference warnings without reshaping the API surface.
- Recorded Sanity as a broad content/editor/API workflow reference while keeping Studio web-editor-first and requiring API/LLM workflows to reuse the same content validation, revision, diff, conflict, audit, and publish services.

### 2026-06-06
- Cleaned up working directory for next feature slice

### Archived Compacted Session History
- [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md).
