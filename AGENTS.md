# Repository Agent Guide

> **Status**: Active  
> **Updated**: 2026-05-19  
> **Owner**: Dominik Letica, OpenAI/Codex  
> **Purpose:** Provide practical, repository-specific instructions for coding agents working on this Symfony application.

## Operating Principles
- Read the existing code and documentation before changing behavior. Prefer local patterns over new abstractions.
- Make use of Symfony native packages and features whenever applicable to keep the codebase as lightweight, compatible and clean as possible.
- Focus on modular implementations and keep file sizes small for better context handling and readability.
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
- `translations/` contains synchronized English and German catalogues.
- `docs/` contains user-facing documentation; `dev/manual/` contains developer documentation; `dev/draft/` contains concepts and feature outlines.
- `dev/WORKLOG.md` tracks sessions, completed work, TODOs, and deferred follow-ups.
- `dev/CLASSMAP.md` indexes relevant callables and their tests.
- `.codex/` contains agent notes, helper scripts, context cache, and environment/tooling notes.

## Before Editing
- Check `dev/WORKLOG.md` for active TODOs and recent context before code changes.
- Check `.codex/ENVIRONMENT.md` before assuming binary paths or local tooling; skip local-only details in cloud or container environments.
- Prefer reusable helper scripts in `.codex/` over ad-hoc command snippets. When adding helpers, document them in `.codex/README.md`.
- For behavior changes, identify the matching documentation and test locations before editing.
- For UI or rendered-output changes, identify affected Twig templates, translations, and routes.

## Change Expectations
- Behavior changes must include matching tests, documentation updates, worklog notes, and class map updates when callables change.
- Documentation-only changes should follow `dev/STYLEGUIDE.md`; tests are not required unless examples or tooling behavior change.
- Translation changes must keep `translations/messages.en.yaml` and `translations/messages.de.yaml` synchronized.
- Refactors before the first public `1.0.0` release may remove obsolete code instead of keeping compatibility shims, but callers, tests, docs, and class map entries must be updated immediately.
- If a requested narrow change exposes unrelated drift, fix it only when it blocks the task; otherwise record the follow-up in `dev/WORKLOG.md`.

## Build and Verification Commands
- `composer install` installs PHP dependencies and verifies required extensions.
- `php -l <path>` checks PHP syntax for a changed file.
- `php bin/console lint:container` validates Symfony container wiring after service or configuration changes.
- `php bin/console tailwind:build` compiles Tailwind CSS.
- `php bin/console asset-map:compile` refreshes AssetMapper output and importmap pins.
- `php bin/console doctrine:migrations:diff` generates schema migrations.
- `php bin/console doctrine:migrations:migrate` applies schema migrations.
- `php bin/phpunit` runs the full PHPUnit suite.
- `php bin/phpunit --coverage-text` runs PHPUnit with quick coverage feedback before PRs.
- `php .codex/compare_translations.php` compares English and German translation keys.
- `php .codex/render.php /<route>` renders a route for Twig and translation review.

## Verification Matrix
- PHP-only logic: run targeted PHPUnit coverage and `php -l` for edited PHP files.
- Service, DI, security, or configuration changes: run targeted tests and `php bin/console lint:container`.
- Twig, translation, or UX copy changes: run `.codex/compare_translations.php` and render affected routes with `.codex/render.php`.
- Asset, Stimulus, or Tailwind changes: run the relevant asset build command and targeted UI/functional checks.
- Doctrine mapping or entity changes: generate or update migrations and run tests covering persistence behavior.
- Documentation changes: verify style, relative links, and alignment with current behavior.
- If a recommended verification step cannot run, record the reason in the final response and, when relevant, in `dev/WORKLOG.md`.

## Coding Style
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
- Keep `translations/messages.en.yaml` and `translations/messages.de.yaml` in sync in the same change.
- User-facing strings include labels, buttons, links, placeholders, help text, validation messages, flash messages, empty states, error pages, and navigation text.
- Logs, developer exceptions, CLI output, test names, and internal debug strings do not need localization.
- For rendered Twig review, use `.codex/render.php /<route>` and then `.codex/compare_translations.php`.

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
- Note completed work, verification performed, and TODOs or follow-ups that remain.
- Do not use the worklog as a substitute for fixing issues that are part of the current task.

## Security and Configuration
- Review `config/packages/security.yaml` for features that affect authentication, authorization, roles, ACLs, or exposed routes.
- Document role and ACL changes in the relevant manual or user guide.
- Do not expose secrets in committed configuration, docs, logs, fixtures, or screenshots.
- Prefer installer-generated secrets and environment-specific configuration over hard-coded values.

## Review Mode
- In code review, lead with findings ordered by severity and include file and line references.
- Verify worklog, documentation, tests, class map, translations, screenshots, security notes, and PR checklist items when they are relevant to the reviewed change.
- Check drift between code and feature drafts in `dev/draft/`; update it only when asked to make changes, otherwise report the drift.
- Review translation coverage with `.codex/compare_translations.php` when user-facing copy changed.
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
- Worklog and session notes: `dev/WORKLOG.md`
- Callable index: `dev/CLASSMAP.md`
- Documentation style guide: `dev/STYLEGUIDE.md`
- Documentation templates: `docs/assets/template.md` and `dev/manual/assets/template.md`
- Environment recap: `.codex/ENVIRONMENT.md`
- Agent tool registry: `.codex/README.md`
