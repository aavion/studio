# Agents Working Directory

> **Status**: Active  
> **Updated**: 2026-05-22. 
> **Owner**: Dominik Letica, OpenAI/Codex  
> **Purpose:** Provides a working directory for coding agents to cache additional information and reusable tools.  

## Index

- Info: [Environment](ENVIRONMENT.md)
- Info: [Project Rules](PROJECT_RULES.md)
- Info: [Framework Version Recap](framework-version-recap.md)
- Info: [Grav Plugin Inspiration Notes](grav-plugin-inspiration-notes.md)
- Info: [Symfony Documentation Notes](symfony-docs-notes.md)
- Audit: [Test Suite Performance Audit 2026-06-01](test-suite-performance-audit-2026-06-01.md)
- Tool: [Render Symfony Output](render.php)
- Tool: [Compare Translation Keys](compare_translations.php)
- Tool: [Global Project Lint](../bin/lint)
- Tool: [Resolve Cloud Artifacts](resolve_cloud_artifacts.php)
- Tool: [Clean Ignored Artifacts](clean_ignored_artifacts.php)

## Usage
- Additional agent notes should live directly under `.codex/`. Add markdown-files here for context-optimization.
- Reusable scripts should live directly under `.codex/`. Add helpers here to avoid re-writing shell snippets.
- Run `bin/lint` to execute the project-wide syntax, container, template, YAML, JavaScript, JSON, Tailwind, and translation-key checks.
- Run `php .codex/compare_translations.php` to compare source catalogue files and keys across all locale directories under `translations/languages/`, using English as the reference locale when available.
- Run `php .codex/resolve_cloud_artifacts.php` to inspect iCloud/Finder artifacts. Add `--apply` to delete safe duplicates and macOS metadata; add `--prefer-base` only after reviewing differing conflict copies.
- Run `php .codex/clean_ignored_artifacts.php` to inspect ignored artifacts. Add `--apply` to delete everything ignored by Git.
