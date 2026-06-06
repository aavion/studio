# Project Rules

> **Status**: Active  
> **Updated**: 2026-06-06  
> **Owner**: Dominik Letica, OpenAI/Codex  
> **Purpose:** Record project-wide decisions agents should remember across sessions.  

## Pre-1.0 Development

- No production deployment must be supported before the first stable `1.0.0` release.
- Before `1.0.0`, prefer clean design over compatibility shims, data migrations, or legacy behavior.
- Keep Doctrine migrations consolidated: the project should contain one current baseline migration before `1.0.0`, not a long chain of development migrations.
- When changing database shape before `1.0.0`, edit the baseline migration, entities, tests, docs, and class map together.
- Remove obsolete code paths instead of preserving backward compatibility unless the user explicitly asks otherwise.

## Database Support

- The application should support MariaDB/MySQL, SQLite, and PostgreSQL through Doctrine DBAL/ORM where practical.
- The automated test environment uses SQLite at `var/test/test.db` via `.env.test`.
- Migration tests should verify that the current baseline migration applies cleanly to SQLite.
- Prefer portable Doctrine types, portable indexes, explicit columns for frequently filtered values, and app-level validation over vendor-specific SQL behavior.
- JSON columns are acceptable for flexible configuration, profile data, schema definitions, labels, ACL group lists, metadata, and field content.
- Do not rely on vendor-specific JSON operators for core read paths unless the feature explicitly declares a minimum database/version requirement.
- If a flexible JSON value becomes a common filter/sort/list field, add a portable explicit column or read-model/index table instead of requiring database-specific JSON indexes.

## Content Revisions

- Content revisions are the stable unit for import previews, structured diffs, review, revert, and retention.
- Imports may stage proposed changes as new revisions, diff those revisions against the active revision, and activate them only after review.
- Cleanup and retention should be driven by nullable active pointers and configuration, not by hard-deleting historical rows by default.

## Architecture And Development Rules

- Modularity is preferred over monolithic implementations. Split large classes, controllers, services, tests, and helpers when the extracted boundary improves responsibility, reuse, readability, or LLM context stability.
- Files should ideally stay below roughly 300 lines when that can be achieved without artificial fragmentation or needless indirection.
- Public and contributor-facing callable, interface, hook, event, command, route, payload, translation-key, and extension-point names must be clear, consistent, and easy to document.
- New or refactored package-owned technical identifiers must follow one stable owner/scope/name convention. The native core/system package owner is `system`; reserve `Studio`/`studio` for branding sourced from `.manifest`, branding assets, or deliberately retained legacy surfaces until those surfaces are intentionally migrated.
- System UI, template, and CSS names should stay branding-neutral. Use `{system|package-slug}-*` for owner-level selectors, `{system|package-slug}-frontend-*` for frontend selectors, `{system|package-slug}-backend-*` for backend selectors, and `{system|package-slug}-{provider-scope}-*` for provider selectors. Package-owned selectors must remain under the package slug and declared scope so package validation can detect collisions.
- Prefer plain domain names when a storage boundary is already owned and no package/user collision is possible, for example `visitor_id`, `message`, `audit`, or `access`. Use the `system` owner prefix only where it protects a shared namespace such as cookies, browser storage, session attributes, service tags, package identities, generated assets, or package-facing extension points.
- CLI command names should prefer Symfony-style domain namespaces such as `assets:*`, `packages:*`, or `scheduler:*` over product branding. A product prefix is acceptable only when it improves clarity, avoids a real namespace collision, or is deliberately retained until a planned pre-`1.0.0` command migration.
- Built-in file logs should stay descriptive and environment-scoped. Prefer `var/log/{APP_ENV}/{message|audit|access}-{rotation_date}.log` over adding a product or system owner prefix to every log filename.
- Runtime errors, validation failures, operational diagnostics, and user-facing feedback should use the shared `Message`, `MessageCode`, `MessageKey`, `WorkflowResult`, or `MessageException` layer wherever practical. Hard exceptions with literal text are allowed only for consciously chosen low-level invariants or unrecoverable adapter failures, and should not be used as a convenience shortcut around structured messages.
- Message code/key constants must live in domain-owned `*MessageCode` and `*MessageKey` catalogue classes close to the owning namespace. `App\Core\Message\MessageCode` and `App\Core\Message\MessageKey` are aggregation entry points, not cross-domain constant warehouses. Catalogue constant names must be namespace/scope-bound with the owning machine namespace first, for example `PACKAGE_INSTALL_*`, `SETUP_PROMPT_*`, or `ACL_GROUP_*`, and their values must stay in the matching machine namespace, for example `package.install.*`, `message.setup.prompt.*`, or `message.acl.group_*`. Future package-owned catalogues must use package-owned namespaces and must not override `system`, core, or other package namespaces; system catalogues win conflicts.
- Public hook descriptors must be registered by domain-owned `EventHookDescriptorProviderInterface` implementations close to the event owner. `PublicEventHookRegistry` aggregates providers for documentation, tooling, debug output, and package API validation; it must not become a central cross-domain list of every hook.
- Available languages must be discovered from translation catalogues, runtime configuration, or explicit content data. Do not hardcode language variants in runtime control flow, default API parameters, or administrative form fields. Content entities may intentionally store separate localized variants per language, but non-content domain data should use one generic label/name unless localized variants are a documented product requirement.
- Core/system cookies must be first-party and technically scoped only, such as visitor identification, session/security binding, language preference, or appearance preference. Core must not set or read cross-site advertising, profiling, or external analytics cookies. Future advertising or external statistics packages must own their own cookie strategy, consent requirements, documentation, and isolation from core technical cookies, for example through a centralized consent interface where packages register their cookie policies.
- Child processes and detached runners must use the central process/environment helpers unless a direct process call is intentionally local and documented. Application subprocesses must inherit Symfony Dotenv-derived configuration and must filter web/CGI request context before execution.
- Add small wrapper or helper APIs when they make public or extension-facing behavior easier to explain, safer to call, or less error-prone.
- Prefer Symfony, Doctrine, Twig, Messenger, Validator, Serializer, Process, Filesystem, Security, EventDispatcher, Form, Translation, and other maintained vendor capabilities over custom infrastructure unless the custom abstraction has clear project-specific value.
- Additional vendor packages are acceptable when they reduce custom maintenance, improve portability/security, or integrate cleanly with Symfony without making the project unnecessarily heavy.
- Keep tests behavior-focused. Secure public behavior, cross-platform assumptions, security boundaries, and data-model guarantees without pinning fragile template, CSS, or implementation details.
- Performance and data-model decisions must be justified by expected behavior and scale, including identifier strategy, indexes, pagination, filtering, sorting, caching, filesystem scans, process spawning, request/visitor identifiers, and full-table or full-tree work.
- Security and misuse resistance must be considered for public entry points, sessions, tokens, visitor/request identity, subprocesses, filesystem access, package/module boundaries, logging, audit data, secrets, and environment propagation.
- Feature drafts, previous implementation choices, and early pre-`1.0.0` assumptions are guidance, not law. Prefer a simpler, safer, more Symfony-native, or more maintainable design when evidence supports changing course.

## Architecture And Drift Audits

- Run broad architecture and project-rules drift audits as reusable review gates, not as one-time cleanup exercises.
- Use audits to verify that current code and new feature work still follow the architecture and development rules above.
- Challenge feature drafts, previous implementation choices, and early pre-`1.0.0` assumptions during audits instead of treating them as binding.
- Review performance and data-model decisions critically, including UUIDs versus auto-increment identifiers, indexes, pagination, filtering, sorting, caching, filesystem scans, process spawning, request/visitor identifiers, and full-table or full-tree work.
- Review security and misuse resistance around public entry points, sessions, tokens, visitor/request identity, subprocesses, filesystem access, package/module boundaries, logging, audit data, secrets, and environment propagation.
- Capture audit findings with evidence, impact, recommendation, and priority. Apply small safe improvements directly; split larger refactors into dedicated follow-up issues or audit PR slices.
