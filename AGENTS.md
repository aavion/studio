# Repository Agent Guide

> **Status**: Active  
> **Updated**: 2026-06-20  
> **Owner**: Dominik Letica, OpenAI/Codex  
> **Purpose:** Provide practical, repository-specific instructions and binding project rules for coding agents working on this Symfony application.  

## Binding Rule Source
- `AGENTS.md` is the binding project-wide rule source for architecture, naming, process, security, localization, and audit decisions.
- This file includes the project rules directly so agents receive the same architecture, process, and verification guidance whenever the repository is loaded.
- Update `AGENTS.md` directly when architecture, naming, process, security, localization, audit, or verification rules change.

## Project Rules

### Pre-1.0 Development
- No production deployment must be supported before the first stable `1.0.0` release.
- Before `1.0.0`, prefer clean design over compatibility shims, data migrations, or legacy behavior.
- Keep Doctrine migrations consolidated: the project should contain one current baseline migration before `1.0.0`, not a long chain of development migrations.
- When changing database shape before `1.0.0`, edit the baseline migration, entities, tests, docs, and class map together.
- Remove obsolete code paths instead of preserving backward compatibility unless the user explicitly asks otherwise.

### Database Support
- The application should support MariaDB/MySQL, SQLite, and PostgreSQL through Doctrine DBAL/ORM where practical.
- The automated test environment uses SQLite at `var/test/test.db` via `.env.test`.
- Migration tests should verify that the current baseline migration applies cleanly to SQLite.
- Prefer portable Doctrine types, portable indexes, explicit columns for frequently filtered values, and app-level validation over vendor-specific SQL behavior.
- JSON columns are acceptable for flexible configuration, profile data, schema definitions, labels, ACL group lists, metadata, and field content.
- Do not rely on vendor-specific JSON operators for core read paths unless the feature explicitly declares a minimum database/version requirement.
- If a flexible JSON value becomes a common filter/sort/list field, add a portable explicit column or read-model/index table instead of requiring database-specific JSON indexes.

### Content Revisions
- Content revisions are the stable unit for import previews, structured diffs, review, revert, and retention.
- Imports may stage proposed changes as new revisions, diff those revisions against the active revision, and activate them only after review.
- Cleanup and retention should be driven by nullable active pointers and configuration, not by hard-deleting historical rows by default.

### Architecture And Development Rules
- Modularity is preferred over monolithic implementations. Split large classes, controllers, services, tests, and helpers when the extracted boundary improves responsibility, reuse, readability, or LLM context stability.
- Files should stay below roughly 300 lines where practical because this size remains reliable for agent context handling, review, and patching. Treat this as a soft limit: split files when a clear responsibility boundary exists, but do not fragment a naturally cohesive file only to satisfy the line target.
- Public and contributor-facing callable, interface, hook, event, command, route, payload, translation-key, and extension-point names must be clear, consistent, and easy to document.
- Reusable technical identifiers must use `App\Core\Validation\IdentifierSpec` for shared validation shapes such as owner slugs, content slugs, dot-path identifiers, snake-case identifiers, handler keys, scheduler-style machine identifiers, database prefixes, ACL group identifiers, and canonical UUIDs. Owner slugs for extensions, generated namespaces, CSS-safe owners, API owner segments, and similar technical ownership boundaries must start with a lowercase letter, use only lowercase letters, digits, and single hyphen-separated segments, and stay at 60 characters or less. Content slugs use the same lowercase single-hyphen shape and length limit, but may start with a digit for natural public URLs such as year-based pages or error pages. Wider database columns are allowed as storage headroom, but validation must remain no wider than storage.
- New or refactored extension-owned technical identifiers must follow one stable owner/scope/name convention. The native core/system extension owner is `system`; reserve `Studio`/`studio` for branding sourced from `.manifest`, branding assets, or deliberately retained legacy surfaces until those surfaces are intentionally migrated.
- System UI, template, and CSS names should stay branding-neutral. Use `{system|extension-slug}-*` for owner-level selectors, `{system|extension-slug}-frontend-*` for frontend selectors, `{system|extension-slug}-backend-*` for backend selectors, and `{system|extension-slug}-{provider-scope}-*` for provider selectors. Extension-owned selectors must remain under the extension slug and declared scope so extension validation can detect collisions.
- Prefer plain domain names when a storage boundary is already owned and no extension/user collision is possible, for example `visitor_id`, `message`, `audit`, or `access`. Use the `system` owner prefix only where it protects a shared namespace such as cookies, browser storage, session attributes, service tags, extension identities, generated assets, or extension-facing integration points.
- CLI command names should prefer Symfony-style domain namespaces such as `assets:*`, `extensions:*`, or `scheduler:*` over product branding. A product prefix is acceptable only when it improves clarity, avoids a real namespace collision, or is deliberately retained until a planned pre-`1.0.0` command migration.
- Built-in file logs should stay descriptive and environment-scoped. Prefer `var/log/{APP_ENV}/{message|audit|access}-{rotation_date}.log` over adding a product or system owner prefix to every log filename.
- Runtime errors, validation failures, operational diagnostics, and user-facing feedback should use the shared `Message`, `MessageCode`, `MessageKey`, `WorkflowResult`, or `MessageException` layer wherever practical. Hard exceptions with literal text are allowed only for consciously chosen low-level invariants or unrecoverable adapter failures, and should not be used as a convenience shortcut around structured messages.
- Message code/key constants must live in domain-owned `*MessageCode` and `*MessageKey` catalogue classes close to the owning namespace. `App\Core\Message\MessageCode` and `App\Core\Message\MessageKey` are aggregation entry points, not cross-domain constant warehouses. Catalogue constant names must be namespace/scope-bound with the owning machine namespace first, for example `EXTENSION_INSTALL_*`, `SETUP_PROMPT_*`, or `ACL_GROUP_*`, and their values must stay in the matching machine namespace, for example `extension.install.*`, `message.setup.prompt.*`, or `message.acl.group_*`. Future extension-owned catalogues must use extension-owned namespaces and must not override `system`, core, or other extension namespaces; system catalogues win conflicts.
- Public hook descriptors must be registered by domain-owned `EventHookDescriptorProviderInterface` implementations close to the event owner. `PublicEventHookRegistry` aggregates providers for documentation, tooling, debug output, and extension API validation; it must not become a central cross-domain list of every hook.
- Available languages must be discovered from translation catalogues, runtime configuration, or explicit content data. Do not hardcode language variants in runtime control flow, default API parameters, or administrative form fields. Content entities may intentionally store separate localized variants per language, but non-content domain data should use one generic label/name unless localized variants are a documented product requirement.
- Core/system cookies must be first-party and technically scoped only, such as visitor identification, session/security binding, language preference, or appearance preference. Core must not set or read cross-site advertising, profiling, or external analytics cookies. Future advertising or external statistics extensions must own their own cookie strategy, consent requirements, documentation, and isolation from core technical cookies, for example through a centralized consent interface where extensions register their cookie policies.
- Child processes and detached runners must use the central process/environment helpers unless a direct process call is intentionally local and documented. Application subprocesses must inherit Symfony Dotenv-derived configuration and must filter web/CGI request context before execution.
- Add small wrapper or helper APIs when they make public or extension-facing behavior easier to explain, safer to call, or less error-prone.
- Prefer Symfony, Doctrine, Twig, Messenger, Validator, Serializer, Process, Filesystem, Security, EventDispatcher, Form, Translation, and other maintained vendor capabilities over custom infrastructure unless the custom abstraction has clear project-specific value.
- Additional vendor packages are acceptable when they reduce custom maintenance, improve portability/security, or integrate cleanly with Symfony without making the project unnecessarily heavy.
- Keep tests behavior-focused. Secure public behavior, cross-platform assumptions, security boundaries, and data-model guarantees without pinning fragile template, CSS, or implementation details.
- Performance and data-model decisions must be justified by expected behavior and scale, including identifier strategy, indexes, pagination, filtering, sorting, caching, filesystem scans, process spawning, request/visitor identifiers, and full-table or full-tree work.
- Security and misuse resistance must be considered for public entry points, sessions, tokens, visitor/request identity, subprocesses, filesystem access, extension/module boundaries, logging, audit data, secrets, and environment propagation.
- Feature drafts, existing implementation choices, and early pre-`1.0.0` assumptions are guidance, not law. Prefer a simpler, safer, more Symfony-native, or more maintainable design when evidence supports changing course.

### Architecture And Drift Audits
- Run broad architecture and project-rules drift audits as reusable review gates.
- Use audits to verify that current code and new feature work still follow the architecture and development rules above.
- Challenge feature drafts, existing implementation choices, and early pre-`1.0.0` assumptions during audits instead of treating them as binding.
- Review performance and data-model decisions critically, including UUIDs versus auto-increment identifiers, indexes, pagination, filtering, sorting, caching, filesystem scans, process spawning, request/visitor identifiers, and full-table or full-tree work.
- Review security and misuse resistance around public entry points, sessions, tokens, visitor/request identity, subprocesses, filesystem access, extension/module boundaries, logging, audit data, secrets, and environment propagation.
- Capture audit findings with evidence, impact, recommendation, and priority. Apply small safe improvements directly; split larger refactors into dedicated follow-up issues or audit PR slices.

## Operating Principles
- Read the existing code and documentation before changing behavior. Prefer local patterns over new abstractions.
- Make use of Symfony native packages and features whenever applicable and keep the codebase as lightweight, compatible and clean as possible.
- Focus on modular implementations and keep file sizes small for optimized context handling and readability.
- Keep changes focused on the user request. Do not refactor unrelated code unless it is required to complete the task safely.
- Preserve user or collaborator changes. Never revert files you did not intentionally change unless the user explicitly asks for it.
- Repository text must be English, including code comments, documentation, commit messages, UI copy source strings, and worklog entries.
- When instructions conflict, follow the user's explicit request for the current task and call out any repository-rule trade-off.
- Prefer graceful production flows: handle recoverable errors, log useful context, and avoid throwing where a user-facing recovery path is possible.

## Project Map
- `src/` contains Symfony PHP code under the `App\` PSR-4 namespace.
- `assets/` contains frontend sources. Stimulus controllers live in `assets/controllers/`; Tailwind and CSS modules live in `assets/styles/`.
- `config/` contains framework configuration. Environment-specific overrides belong in `.env.local*` files only.
- `templates/` contains Twig views; `public/` exposes built assets and `index.php`.
- `tests/` mirrors production code for PHPUnit unit, integration, functional, and end-to-end coverage.
- `translations/` contains modular source catalogues and generated runtime catalogues.
- `docs/` contains user-facing documentation; `dev/manual/` contains developer documentation; `dev/draft/` contains concepts and feature outlines.
- `dev/WORKLOG.md` tracks session notes with branch/PR context, completed work, TODOs, and deferred follow-ups.
- `dev/CLASSMAP.md` indexes relevant callables and their tests.
- `.codex/` contains agent-only notes, reusable development helper scripts, context cache, and environment/tooling notes. Production code, tests, build scripts, and release workflows must not depend on files under `.codex/`.

## Before Editing
- Check the project rules in this file and `dev/WORKLOG.md` for active TODOs and recent context before code changes.
- Check `.codex/ENVIRONMENT.md` before assuming binary paths or local tooling; skip local-only details in cloud or container environments.
- Check `.codex/framework-version-recap.md` before routine framework or dependency work; treat it as a version-pinned documentation cache and refresh the relevant section when installed versions change, when the cached note is unclear, or when the task depends on precise current API behavior.
- Prefer reusable `.codex/` helpers over ad-hoc command snippets for agent-only development tasks. Promote helpers into project tooling such as `bin/` or Symfony commands when they become useful to other developers or required by tests, CI, build, setup, or production workflows.
- For behavior changes, identify the matching documentation and test locations before editing.
- For UI or rendered-output changes, identify affected Twig templates, translations, and routes.

## Change Expectations
- Behavior changes must include matching tests, documentation updates, worklog notes, and class map updates when callables change.
- Documentation-only changes should follow `dev/STYLEGUIDE.md`; tests are not required unless examples or tooling behavior change.
- Translation changes must keep matching source catalogue files and keys synchronized across all locale directories under `translations/languages/`; runtime `translations/messages.*.yaml` files are generated from those sources.
- Refactors before the first public `1.0.0` release may remove obsolete code instead of keeping compatibility shims, but callers, tests, docs, and class map entries must be updated immediately.
- If a requested narrow change exposes unrelated drift, fix it only when it blocks the task; otherwise record the follow-up in `dev/WORKLOG.md`.

### Review Finding Fixes
- When available and applicable, prefer the project-local `fix-review-findings` Codex skill for concrete review comments, PR findings, Cloud Review findings, or local review findings.
- Before applying a fix for a review finding, trace the affected boundary from source to sink and inspect adjacent, related, and analogous code paths that share the same classifier, subscriber, guard, resolver, route family, subject selection, response behavior, storage boundary, or policy decision.
- Prefer fixing the narrowest central boundary that covers all affected paths. Apply a path-local fix only when evidence shows the issue is truly path-specific.
- Keep review fixes simple, modular, and minimally invasive. Do not broaden them into unrelated refactors, compatibility shims, or speculative redesigns.
- While tracing the affected boundary, actively look for additional unreported edge cases, including bypasses, abuse paths, privacy leaks, performance regressions, setup/pre-auth behavior, disabled-feature fallbacks, response redaction, and cache/storage failure behavior.
- Fix small in-scope adjacent issues directly when they share the same boundary and risk profile. Record larger or behavior-changing follow-ups in `dev/WORKLOG.md` instead of hiding them inside the review fix.
- Add or update regression coverage for the reported finding and any adjacent paths changed by the fix. When an analogous path is inspected and intentionally not changed, make that reasoning clear in the worklog, final notes, or PR response where useful.

### PR Readiness Audits
- When available and applicable, prefer the project-local `audit-pr-readiness` Codex skill for PR-readiness checks, ready-for-review preparation, and PR template completion.
- Before marking a branch, pull request, or feature slice ready for review, run the PR-readiness checklist as a real audit pass over the branch diff and the affected runtime surfaces. Do not treat checklist items as passive boxes to tick.
- The audit must explicitly review security/privacy considerations; public entry points; authentication, authorization, sessions, secrets, browser storage, and response redaction; extension/module boundaries; access levels; route/API/live endpoint scopes; naming and collision risks; setup/init/CI behavior; cross-platform behavior; disabled-feature fallbacks; process and environment handling; default seed coverage for implemented config keys; translations and user-facing copy; project-rule, architecture, naming, documentation, and performance drift; and captured follow-up tasks.
- Use evidence from code inspection, focused tests, render checks, linting, documentation diffs, class map/worklog updates, and seed/default coverage as appropriate for the changed surface. If a checklist item is not applicable, record why instead of silently skipping it.
- Fix small readiness issues directly when they are in scope and low risk. Record larger, behavior-changing, or separate-domain issues in `dev/WORKLOG.md` with a clear next action.
- PR notes must summarize the readiness audit outcome, including verification commands, skipped checks or proof gaps, documentation/worklog/classmap status, translation status, security/privacy considerations, and remaining follow-ups.

## Build and Verification Commands
- `bin/init` initializes the repository, synchronizes configured Git submodules, refreshes dependencies and assets, locks referenced Symfony UX icons locally when possible, and is the preferred recovery path for broken or incomplete `vendor/` packages because it removes an existing `vendor/` tree before Composer runs.
- `composer install` installs PHP dependencies and verifies required packages.
- `bin/lint` runs the full project lint suite, including Markdown parse checks, local Symfony UX icon reference checks, and a Git whitespace check that excludes Markdown hard line breaks; pass one or more files or directories to run focused type-based checks, or use `bin/lint --diff`, `bin/lint --staged`, `bin/lint --diff=<target..source>`, or `bin/lint --changed=<target..source>` to lint supported Git changes when Git and a work tree are available.
- `php -l <path>` checks PHP syntax for a changed file.
- `php bin/console lint:container` validates Symfony container wiring after service or configuration changes.
- `php bin/console tailwind:build` compiles Tailwind CSS.
- `php bin/console asset-map:compile` is production/release-only. Do not run it for local development or normal verification; if `public/assets/` is created locally, remove that generated production output from the worktree.
- `php bin/console ux:icons:lock` imports referenced Symfony UX/Iconify icons into `assets/icons`; commit the resulting SVGs as versioned UI dependency snapshots, but avoid bulk-locking complete icon sets without a concrete need.
- `php bin/console doctrine:migrations:diff` generates schema migrations.
- `php bin/console doctrine:migrations:migrate` applies schema migrations.
- `php bin/phpunit` runs the full PHPUnit suite.
- `php bin/phpunit --coverage-text` runs PHPUnit with quick coverage feedback before PRs.
- `bin/jstest` runs native Node.js JavaScript behavior tests from `tests/assets/**/*.test.mjs` through `node --test`; pass test files or Node test-runner options for focused runs. If Node.js is not available, the command prints a skip notice and exits successfully so only real JavaScript test failures fail CI.
- `bin/lint` includes the translation source catalogue file/key comparison for release-safe validation without requiring `.codex/`.
- Before committing, use `bin/lint --diff` or the relevant focused `bin/lint <path...>` for Git-aware whitespace checks. Markdown files may contain intentional two-space hard line breaks; preserve those hard breaks when reviewing whitespace output from raw Git commands.
- `php bin/console render:route /<route>` renders a route for Twig, translation, and debug user/role review.

## Verification Matrix
- PHP-only logic: run targeted PHPUnit coverage and `php -l` for edited PHP files.
- Service, DI, security, or configuration changes: run targeted tests and `php bin/console lint:container`.
- Twig, translation, or UX copy changes: run `bin/lint <changed translation/template paths...>` and render affected routes with `php bin/console render:route /<route>` when available.
- Asset or Stimulus changes: prefer `bin/lint <changed path...>` for focused JavaScript, JSON, CSS, YAML, Twig, Markdown, and PHP syntax checks, run `bin/jstest` or focused `bin/jstest <test-file...>` when DOM-free JavaScript behavior can be covered, then run the relevant development asset build command and targeted UI/functional checks when build output or rendering can change. Do not use production-only `asset-map:compile` for local verification.
- Focused CSS checks use the strict CSS parser and may report Tailwind-specific directives or generated modern at-rules such as `@apply`, `@theme`, or `@supports` as unsupported syntax; treat the accompanying linter note as context, and use `php bin/console tailwind:build` for the authoritative full Tailwind validation.
- Doctrine mapping or entity changes: generate or update migrations and run tests covering persistence behavior.
- Documentation changes: run `bin/lint <changed markdown paths...>` for Markdown parse coverage, then verify style, relative links, and alignment with current behavior.
- If a recommended verification step cannot run, record the reason in the final response and, when relevant, in `dev/WORKLOG.md`.

## Coding Style
- Respect `.editorconfig` for whitespace and indentation rules; Markdown intentionally disables automatic trailing-whitespace trimming because two trailing spaces are meaningful hard line breaks.
- PHP follows PSR-12 with four-space indentation and `declare(strict_types=1);` where applicable.
- YAML keys use `snake_case`.
- Twig templates use lowercase, hyphenated filenames such as `layouts/base.html.twig`.
- Stimulus controllers use `snake_controller.js` names and register through the Symfony loader.
- Use automated formatters when available, such as `php-cs-fixer`; otherwise keep diffs manually PSR-12-compliant.
- Keep comments succinct and useful. Add comments only when they clarify non-obvious intent.
- Do not commit secrets. Store local values in `.env.local` or Symfony secrets.

## Translations
- Every user-facing string in Twig, PHP, or JavaScript must use a deterministic translation key.
- Translation keys follow `namespace.section.token`, for example `installer.environment.form.instance_name`.
- Keep matching translation source catalogue files and keys in sync across all locale directories in the same change. Source files live under `translations/languages/{locale}/*.yaml`, with English used as the comparison reference when available and the message-layer source named `message.yaml`; runtime `translations/messages.{locale}.yaml` catalogues are generated for Symfony's default domain.
- User-facing strings include labels, buttons, links, placeholders, help text, validation messages, flash messages, empty states, error pages, and navigation text.
- Logs, developer exceptions, CLI output, test names, and internal debug strings do not need localization.
- For rendered Twig review, use `php bin/console render:route /<route>` when available and then `bin/lint <changed translation/template paths...>`.

## Documentation
- Follow `dev/STYLEGUIDE.md` for all Markdown documentation.
- Use `docs/assets/template.md` for user guides and `dev/manual/assets/template.md` for developer guides.
- Store user-facing screenshots under `docs/assets/`; store developer-manual screenshots under `dev/manual/assets/`.
- Update `docs/`, `dev/manual/`, and `dev/draft/` when behavior changes make existing documentation incomplete or inaccurate.
- Read relevant Markdown files end-to-end before editing them. Full documentation sweeps are required for release or explicit documentation-review tasks, not for every small code change.
- Log deferred documentation work in `dev/WORKLOG.md` with an owner or next action.

## Tests
- Use PHPUnit with namespaces mirroring production code under `tests/`.
- Test method names should be clear, using `testSomething()` or `it_should_doSomething()`.
- Keep tests deterministic: seed fixtures explicitly, avoid external services, and clean up database state.
- Add or adjust coverage for every behavior change. Do not skip tests for new logic.
- Prefer targeted tests while developing, then run broader suites before PRs or high-risk changes.

## Class Map
- Keep `dev/CLASSMAP.md` current when adding, removing, or changing relevant callables.
- Relevant callables include controller actions, console commands, services with public behavior, event listeners/subscribers, form types, Twig extensions/components, security voters, and other entry points contributors need to find quickly.
- Include or update related test references where available.

## Worklog
- Record meaningful code, behavior, documentation, and tooling changes in `dev/WORKLOG.md`.
- Keep active worklog entries session-based with branch context, using headings in the form `### YYYY-MM-DD branch-name`; continue appending sessions under the active branch so PR reviewers retain the full change context.
- Move completed branch entries to `dev/WORKLOG_HISTORY.md` when switching branches or after the PR is merged; new Codex sessions stay under the active branch entry.
- Note completed work, verification performed, and TODOs or follow-ups that remain.
- Do not use the worklog as a substitute for fixing issues that are part of the current task.

## Security and Configuration
- Review `config/packages/security.yaml` for features that affect authentication, authorization, roles, ACLs, or exposed routes.
- Document role and ACL changes in the relevant manual or user guide.
- Do not expose secrets in committed configuration, docs, logs, fixtures, or screenshots.
- Prefer installer-generated secrets and environment-specific configuration over hard-coded values.

## Review Mode
- In code review, lead with findings ordered by severity and include file and line references.
- For explicit complete local branch, PR, broad pre-PR, exhaustive mini-model, or Cloud-Review-like local reviews, prefer the project-local `local-code-review` Codex skill when available and applicable.
- Review-fix implementation must follow the Review Finding Fixes rules under Change Expectations before applying code changes.
- PR-readiness sign-off must follow the PR Readiness Audits rules under Change Expectations instead of only copying checklist items.
- Verify worklog, documentation, tests, class map, translations, screenshots, security notes, and PR checklist items when they are relevant to the reviewed change.
- Check drift between code and feature drafts in `dev/draft/`; update it only when asked to make changes, otherwise report the drift.
- Review translation coverage with `bin/lint <changed translation paths...>` when user-facing copy changed.
- Review relevant Markdown files for completeness and link health. Run or schedule link checks when possible.

## Commit and Pull Request Guidance
- Use present-tense imperative commit messages scoped to one logical change, for example `Add snapshot publish command`.
- Reference issues with `[#123]` or GitHub keywords when applicable.
- Pull requests must include a change summary, testing notes, documentation notes, and any security or migration considerations.
- Keep PRs focused and record deferred follow-up work in `dev/WORKLOG.md`.

## References
- README entry point: `README.md`
- Developer manual: `dev/manual/**`
- User manual: `docs/**`
- Feature drafts and outlines: `dev/draft/**`
- Worklog session notes with branch/PR context: `dev/WORKLOG.md`
- Callable index: `dev/CLASSMAP.md`
- Documentation style guide: `dev/STYLEGUIDE.md`
- Documentation templates: `docs/assets/template.md` and `dev/manual/assets/template.md`
- Environment recap: `.codex/ENVIRONMENT.md`
- Agent tool registry: `.codex/README.md`
