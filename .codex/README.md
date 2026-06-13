# Agents Working Directory

> **Status**: Active  
> **Updated**: 2026-06-13  
> **Owner**: Dominik Letica, OpenAI/Codex  
> **Purpose:** Provides a working directory for coding agents to cache additional information and reusable tools.  

## Index

### Active Context
- Info: [Environment](ENVIRONMENT.md)
- Info: [Framework And Dependency Recap](framework-version-recap.md)
- Reference: [Grav Plugin Inspiration Notes](grav-plugin-inspiration-notes.md)

### Historical Plans And Audits
- Plan: [Branding-Neutral Naming Migration Plan 2026-06-06](branding-naming-migration-plan-2026-06-06.md) - completed naming migration context.
- Audit: [Test Suite Performance Audit 2026-06-01](test-suite-performance-audit-2026-06-01.md) - closed/deferred reference.
- Audit: [Project Readiness Drift Audit 2026-06-05](audit-project-readiness-2026-06-05.md) - first broad audit context and finding catalogue.
- Audit: [Project Readiness Drift Audit Second Pass 2026-06-06](audit-project-readiness-second-pass-2026-06-06.md) - completed second-pass audit and final gate notes.

### Tools
- Tool: [Compare Translation Keys](compare_translations.php)
- Tool: [Global Project Lint](../bin/lint)
- Tool: [Resolve Cloud Artifacts](resolve_cloud_artifacts.php)
- Tool: [Clean Ignored Artifacts](clean_ignored_artifacts.php)

## Usage
- Binding project rules live in [`../AGENTS.md`](../AGENTS.md). Do not keep duplicate project-rule files under `.codex/`.
- Additional agent notes should live under `.codex/`. Prefer short, dated Markdown files with clear `Status`, `Updated`, `Owner`, and `Purpose` metadata.
- Reusable scripts should live under `.codex/`. Add helpers here to avoid re-writing shell snippets.
- Historical audits may stay here while their findings are still actively referenced. Move resolved or superseded details into `dev/WORKLOG.md`, feature drafts, or issue trackers before deleting the audit note.
- Documentation-reference notes such as the framework recap are version-pinned cache aids. Check them before routine work, refresh them from Context7 or official documentation when installed versions change, when the cached note is unclear, or when the task depends on version-sensitive behavior, and write useful new findings back into the cache.
- Run `bin/lint` to execute the project-wide syntax, container, template, YAML, JavaScript, JSON, Markdown parse, Tailwind, translation-key, and non-Markdown Git whitespace checks.
- Run `bin/lint --diff` to lint supported files in the current staged or unstaged Git diff. Use `bin/lint --staged` for staged-only changes, or `bin/lint --diff=<target..source>`, `bin/lint --diff:<target..source>`, or `bin/lint --changed=<target..source>` for an explicit Git diff range. Git-dependent checks skip cleanly when Git or a work tree is unavailable.
- Use `php bin/console render:route /path` for project-wide CLI route rendering with optional `--role`, `--user`, `--method`, `--host`, `--https`, and `--setup-completed=0` debug context.
- Run `php .codex/compare_translations.php` to compare source catalogue files and keys across all locale directories under `translations/languages/`, using English as the reference locale when available.
- Run `php .codex/resolve_cloud_artifacts.php` to inspect iCloud/Finder artifacts. Add `--apply` to delete safe duplicates and macOS metadata; add `--prefer-base` only after reviewing differing conflict copies.
- Run `php .codex/clean_ignored_artifacts.php` to inspect ignored artifacts. Add `--apply` to delete everything ignored by Git.
