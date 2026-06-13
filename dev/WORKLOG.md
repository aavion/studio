# Developer Worklog

> **Status**: Active  
> **Updated**: 2026-06-13  
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

## Branch Logs
**Usage:** Keep session notes in the active worklog and include the current branch in headings, using the form `### YYYY-MM-DD branch-name`. Continue appending new session notes under the active branch so reviewers can see the full PR context in one place. When switching to a different branch or after a PR is merged, compact the completed branch entry into [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md), then create the new branch entry at the top. Record every meaningful committed or completed change, including verification and follow-ups.

### 2026-06-12 docs-cleanup
- Refreshed the `.codex` context inventory: marked the branding-neutral naming migration and first readiness audit as completed/historical, removed the obsolete standalone Symfony docs notes, made the framework recap the version-pinned dependency documentation cache, and updated it with current installed-dependency guidance for Symfony 8.1, Doctrine ORM/DBAL, Twig 3.27, Tailwind v4/TailwindBundle, Symfony UX, CommonMark, and PHPUnit 13.
- Extended `bin/lint` into the all-in-one diff linting entry point: it now supports `--diff`, `--diff=<target..source>`, and `--diff:<target..source>`, collects staged/unstaged or explicit Git diff files when Git is available, lints extensionless PHP scripts such as `bin/lint`, and runs a non-Markdown Git whitespace check that preserves intentional Markdown hard line breaks.
- Added Markdown parse coverage to `bin/lint` using the existing League CommonMark/GFM dependency so Markdown targets produce a real parse/render smoke-check instead of being reported as unsupported.
- Documented the Git whitespace/Markdown hard-break rule in `AGENTS.md`, updated the `.codex` tool index and class map for the new lint modes, and compacted the old 2026-06-07 API session into `dev/WORKLOG_HISTORY.md`.
- Moved the binding project rules from `.codex/PROJECT_RULES.md` into `AGENTS.md` so architecture, naming, pre-`1.0.0`, database, content-revision, security, and audit rules remain available when Codex project context changes or the `.codex` notes are not loaded.
- Removed the obsolete `.codex/PROJECT_RULES.md` duplicate, updated `.codex/ENVIRONMENT.md` for the new `/Volumes/Projekte/studio` checkout path, and refreshed `.codex/README.md` with active context, historical audit, tool, and cleanup guidance.
- Verified `.codex/resolve_cloud_artifacts.php` reports no cloud conflict artifacts; `.codex/clean_ignored_artifacts.php` dry-run still lists normal ignored generated/dependency directories such as `vendor/`, `var/`, `assets/vendor/`, `translations/runtime/`, and package build outputs, so no deletion was applied.

### 2026-06-13 docs-cleanup
- Moved route rendering from the `.codex` helper into project code with `php bin/console render:route /path`, including optional debug role, existing user, method, host, HTTPS, setup-completion, browser-auth, and API debug context support; removed the obsolete `.codex/render.php` helper and updated render-review references.
- Extended `bin/lint` with `--staged` and `--changed=<target..source>` while keeping Git-dependent target collection and whitespace checks graceful when Git or a work tree is unavailable.
- Reviewed the newly installed Symfony UX package set, kept optional UX Stimulus controllers lazy, removed generated React/Vue/Icon demo files, and tied committed Mercure defaults to `DEFAULT_URI` and `APP_SECRET` for development while documenting production override expectations.
- Updated the class map, dependency recap, local agent tooling notes, and active worklog/history to reflect the render command, lint modes, Symfony UX baseline, and branch-oriented worklog boundary.
- Changed worklog retention from per-session compaction to branch-scoped archival, restored the current `docs-cleanup` branch context from history, and mirrored the rule in `AGENTS.md`.
- Added Symfony UX icon locking to `bin/init` and the package-aware asset rebuild queue as non-blocking dependency steps, and registered active package template paths for icon/AssetMapper console scans so core and package icon references can be imported locally when Iconify is reachable without breaking offline CI or admin rebuilds.
- Added a local-only Symfony UX icon reference check to `bin/lint` so static Twig icon references fail when the required locked SVG is missing, without running the mutating network-backed `ux:icons:lock` command.
- Documented that locked SVGs in `assets/icons` should be committed as reviewable dependency snapshots while avoiding bulk-locking complete upstream icon sets by default.
- Declared `ext-sodium` as a direct Composer platform requirement and added it to the PR verification runner because the Symfony Mercure/JWT dependency chain requires `lcobucci/jwt`, which requires Sodium.
- Clarified `AGENTS.md` wording around session notes with branch/PR context and the boundary between agent-only `.codex` helpers and project-wide tooling.
- Normalized `AGENTS.md` wording so the document reads as a standalone first-version guide rather than as a patch over earlier agent habits.
- Disabled UX Translator TypeScript type dumps in production because the current AssetMapper setup uses JavaScript, not TypeScript, and recorded the UX Turbo 3.1 stream-listen deprecation in the dependency recap.
- Added cache warmup to `bin/init` and `ux:translator:warm-cache` to the package-aware asset rebuild queue so `var/translations/index.js` exists before AssetMapper resolves `assets/translator.js`.

### Archived Compacted Branch History
- [WORKLOG_HISTORY.md](WORKLOG_HISTORY.md).
