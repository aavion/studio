# Repository Agent Guide for Studio

> **Status**: Draft  
> **Updated**: 2026-10-27  
> **Version**: 0.0.0
> **Code Owner**: @dominikletica  

# Binding Rule Source
- `AGENTS.md` is the binding project-wide rule source for all decisions.
- This file includes the project rules directly so agents receive the same architecture, process, and verification guidance whenever the repository is loaded.
- Update `AGENTS.md` directly when architecture, naming, process, security, localization, audit, or verification rules change.

# Project Rules

## Pre-1.0 Development
- No production deployment must be supported before the first stable `1.0.0` release.
- Before `1.0.0`, prefer clean design over compatibility shims, data migrations, or legacy behavior.
- Remove obsolete code paths instead of preserving backward compatibility unless the user explicitly asks otherwise.

## Data Management
- The application should use Grav's native way to store data.
- When flat-file- or flex-storage is not practical, use SQLite instead.

## Architecture And Development Rules
- Modularity is preferred over monolithic implementations.
- Files should stay below roughly 300 lines where practical because this size remains reliable for agent context handling, review, and patching. Treat this as a soft limit: split files when a clear responsibility boundary exists, but do not fragment a naturally cohesive file only to satisfy the line target.
- Public and contributor-facing callable, interface, hook, event, command, route, payload, translation-key, and extension-point names must be clear, consistent, and easy to document.
- New or refactored package-owned technical identifiers must follow one stable owner/scope/name convention.
- UI, translations templates and CSS names should stay inside the studio-scope.
- Every user facing string must be translatable and be shipped in English (en) and German (de) language.
- Prefer methods provided by Grav or it's vendor packages over custom infrastructure unless the custom abstraction has clear project-specific value.
- Additional vendor packages are acceptable when they reduce custom maintenance, improve portability/security, or integrate cleanly with Grav without making the project unnecessarily heavy.
- Keep tests behavior-focused. Secure public behavior, cross-platform assumptions, security boundaries, and data-model guarantees without pinning fragile template, CSS, or implementation details.
- Performance and data-model decisions must be justified by expected behavior and scale.
- Security and misuse resistance must be considered alongside development.
- Feature drafts, existing implementation choices, and early pre-`1.0.0` assumptions are guidance, not law. Prefer a simpler, safer, more native, or more maintainable design when evidence supports changing course.

# Operating Principles
- Read the existing code and documentation before changing behavior. Prefer local patterns over new abstractions.
- Make use of Grav native features whenever applicable and keep the codebase as lightweight, compatible and clean as possible.
- Focus on modular implementations and keep file sizes small for optimized context handling and readability.
- Keep changes focused on the user request. Do not refactor unrelated code unless it is required to complete the task safely.
- Preserve user or collaborator changes. Never revert files you did not intentionally change unless the user explicitly asks for it.
- Repository text must be English, including code comments, documentation, commit messages, UI copy source strings, and worklog entries.
- When instructions conflict, follow the user's explicit request for the current task and call out any repository-rule trade-off.
- Prefer graceful production flows: handle recoverable errors, log useful context, and avoid throwing where a user-facing recovery path is possible.

# Project Map
- This folder will be live directly inside Grav's `/user/plugins` directory.
- Plugins must follow Grav's official plugin development guidelines.
- Themes must follow Grav's official theme development guidelines.

# Before Editing
- Check the project rules in this file and the changelog for active TODOs and recent context before code changes.
- Check `.codex/ENVIRONMENT.md` before assuming binary paths or local tooling; skip local-only details in cloud or container environments.
- For behavior changes, identify the matching documentation and test locations before editing.
- For UI or rendered-output changes, identify affected templates, translations, and assets.

# Change Expectations
- Behavior changes must include matching tests, documentation updates, and changelog notes.
- Documentation-only changes should follow `.codex/STYLEGUIDE.md`; tests are not required unless examples or tooling behavior change.
- Translation changes must keep matching catalogue files and keys synchronized across all provided locales.
- Refactors before the first public `1.0.0` release may remove obsolete code instead of keeping compatibility shims, but callers, tests, docs, and other entries must be updated immediately.
- If a requested narrow change exposes unrelated drift, fix it immediately.
- When addressing review findings, trace adjacent and analogous code paths that share the same policy, transition, or boundary, and apply or explicitly rule out the same fix there to avoid one-path-only hardening.

# Tests and Verification
- A local copy of Grav is located in `/Volumes/Projekte/testing`. It may be used for testing and verification purposes.
- If a symlink to the project folder does not exist where it is expected, create one (ln -s with relative path).

# Coding Style
- Respect `.editorconfig` for whitespace and indentation rules; Markdown intentionally disables automatic trailing-whitespace trimming because two trailing spaces are meaningful hard line breaks.
- PHP follows PSR-12 with four-space indentation and `declare(strict_types=1);` where applicable.
- YAML keys use `snake_case`.
- Twig templates use lowercase, hyphenated filenames such as `layouts/base.html.twig`.
- JS controllers use `snake_controller.js` names.
- Use automated formatters when available, such as `php-cs-fixer`; otherwise keep diffs manually PSR-12-compliant.
- Keep comments succinct and useful. Add comments only when they clarify non-obvious intent.
- Do not commit secrets.

# Translations
- Every user-facing string in Twig, PHP, or JavaScript must use a deterministic translation key.
- Translation keys follow Grav's specifications.
- Keep matching translation catalogue files and keys in sync across all locales in the same change.
- Logs, developer exceptions, CLI output, test names, and internal debug strings do not need localization.

# Documentation
- Follow `.codex/STYLEGUIDE.md` for all Markdown documentation.
- Keep guides simple and inside `README.md`.
- Read relevant Markdown files end-to-end before editing them.
- Log deferred documentation work in `CHANGELOG.md` with an owner or next action.

# Tests
- Use PHPUnit with namespaces mirroring production code.
- Test method names should be clear, using `testSomething()` or `it_should_doSomething()`.
- Keep tests deterministic: seed fixtures explicitly, avoid external services, and clean up states.
- Add or adjust coverage for every behavior change. Do not skip tests for new logic.
- Prefer targeted tests while developing, then run broader suites before PRs or high-risk changes.

# Worklog
- Record meaningful code, behavior, documentation, and tooling changes under the `# WORKLOG`-heading in `CHANGELOG.md` (create if missing).
- Keep active entries session-based with context, using headings in the form `## YYYY-MM-DD session-name`; continue appending sessions under the active branch so PR reviewers retain the full change context.
- Compress completed entries under the `next`-version-heading. These compressed copy should only contain meaningful user-facing changes (standard changelog-wording).
- Do not use the log as a substitute for fixing issues that are part of the current task.

# Review Mode
- In code review, lead with findings ordered by severity and include file and line references.
- Verify worklog, documentation, tests, translations, notes, and PR checklist items when they are relevant to the reviewed change.
- Check drift between code and feature drafts in `.codex/DRAFT.md`; update it only when asked to make changes, otherwise report the drift.
- Review translation coverage when user-facing copy changed.
- Review relevant Markdown files for completeness and health.

# Commit and Pull Request Guidance
- Use present-tense imperative commit messages scoped to one logical change, for example `Add snapshot publish command`.
- Reference issues with `[#123]` or GitHub keywords when applicable.
- Pull requests must include a change summary, testing notes, documentation notes, and any security or stability considerations.
- Keep PRs focused and record deferred follow-up work in `CHANGELOG.md`.

# References
- README: `README.md`
- Feature drafts and outlines: `.codex/DRAFT.md`
- Worklog session notes: `CHANGELOG.md`
- Documentation style guide: `.codex/STYLEGUIDE.md`
- Environment recap: `.codex/ENVIRONMENT.md`