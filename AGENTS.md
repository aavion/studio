# Repository Guidelines

> **Status**: N/A  
> **Updated**: 2026-05-15  
> **Owner**: OpenAI/Codex  
> **Purpose:** Provides directives and usage hints to use by coding agents.  

## Project Structure & Module Organization
- `src/` holds Symfony PHP code (controllers, services, domain logic). Follow PSR-4 namespaces under `App\`.
- `assets/` contains frontend sources. Place Stimulus controllers under `assets/controllers/` and Tailwind/CSS modules in `assets/styles/`.
- `config/` stores framework configuration. Keep environment-specific overrides in `.env.local*` files only.
- `templates/` hosts Twig views; `public/` exposes built assets and the front controller (`index.php`).
- `tests/` mirrors `src/` for PHPUnit suites (unit, integration, end-to-end).
- `.codex/` is reserved for caching and reusable tooling/snippets.
- Documentation hubs: developer content in `dev/manual/`, user-facing manuals in `docs/`, shared worklog in `dev/WORKLOG.md`, drafts/outlines in `dev/draft/`.

## Build, Test, and Development Commands
- `composer install` – install PHP dependencies and confirm required extensions (`ext-intl`, `ext-sqlite3`, `ext-fileinfo`).
- `php bin/console tailwind:build` – compile Tailwind CSS via Symfony Tailwind Bundle.
- `php bin/console asset-map:compile` – refresh AssetMapper output (JS entrypoints & importmap pins).
- `php bin/console doctrine:migrations:diff` / `php bin/console doctrine:migrations:migrate` – generate and apply schema migrations.
- `php bin/phpunit` – execute the complete PHPUnit test suite; append `--coverage-text` for quick coverage feedback.

## Coding Style & Naming Conventions
- PHP adheres to PSR-12: four-space indentation, `declare(strict_types=1);` where applicable, snake_case for YAML keys.
- Twig templates use lowercase, hyphenated filenames (`layouts/base.html.twig`).
- Stimulus controllers follow `snake_controller.js` naming and register via the Symfony loader.
- Apply automated formatters when available (e.g. `php-cs-fixer`); otherwise rely on IDE PSR-12 formatting and composer-normalized JSON.
- Repository text (code comments, docs, commits) must remain English, even when collaboration happens in other languages.
- **Translations:** Every user-facing string in Twig, PHP, or JavaScript must be referenced via a deterministic translation key following the `namespace.section.token` pattern (e.g. `installer.environment.form.instance_name`). Keep the English catalogue (`translations/messages.en.yaml`) and the German catalogue (`translations/messages.de.yaml`) in sync whenever strings change.
- Maintainers keep `dev/CLASSMAP.md` up to date with every callable (services, commands, components, ...) so contributors can locate references without codewide searches.
- Every code change must ship with corresponding documentation and tests:
  - Update feature notes, manuals, and class map entries in the same commit when behaviour changes.
  - Add or adjust PHPUnit/functional coverage that proves the new behaviour – never skip tests.
  - Record the work and TODO state in `dev/WORKLOG.md` as part of the change, not afterwards.

## Testing Guidelines
- Use PHPUnit with namespaces mirroring production code (`tests/Unit/App/...`, `tests/Integration/App/...`).
- Test methods follow `testSomething()` or `it_should_doSomething()` naming for clarity.
- Keep tests deterministic: seed fixtures via Doctrine, avoid external services, and clean up database state.
- Run `php bin/phpunit --coverage-text` before opening a PR; ensure new logic is covered or document gaps in `dev/WORKLOG.md`.

## Commit & Pull Request Guidelines
- Write present-tense imperative commit messages (`Add snapshot publish command`) scoped to one logical change.
- Reference issues with `[#123]` or GitHub keywords when applicable.
- Pull requests must include: change summary, testing notes and mention of new/updated docs or tests.
- Keep PRs focused and call out follow-up work in the worklog (`dev/WORKLOG.md`) when deferring tasks.

## Session Workflow & Documentation
- Before coding, review `dev/WORKLOG.md` and open the next session entry; align TODOs with the repository state.
- Scan pending documentation changes (`dev/manual/**`, `docs/**`, `dev/draft/**`) and update immediately or log follow-ups.
- After each feature/refactor, cross-check code vs. docs; update docs on the spot or record the discrepancy with owner and next steps.
- When closing a session, read relevant Markdown files end-to-end to catch drift, document outcomes in the worklog, and note pending actions.
- Prefer non-throwing flows in production code: handle errors, log context-rich messages, and surface recoverable issues gracefully.
- Follow the documentation style guide (`dev/STYLEGUIDE.md`). Use templates (`docs/assets/template.md` / `dev/manual/assets/template.md`) when creating new guides. Place screenshots under `docs/assets/` (`dev/manual/assets/` respectively) and update `dev/CLASSMAP.md` with new callables.

## Compatibility & Refactoring Policy
- Until the first public major release (1.0.0), backwards compatibility is not required. Favour clean refactors over legacy shims and remove obsolete code; update callers and documentation immediately when behaviour changes.

## Security & Configuration Tips
- Never commit secrets; store environment values in `.env.local` or Symfony’s secrets vault. Releases should rely on installer-generated secrets.
- Validate container wiring via `php bin/console lint:container` after introducing or refactoring services.
- Review security rules in `config/packages/security.yaml` for each feature and ensure role/ACL updates are documented.

## References
- Developer manual: `dev/manual/**`
- User manual: `docs/**`
- Worklog & session notes: `dev/WORKLOG.md`
- Concept outlines: `dev/draft/**`
- Environment recap: `.codex/ENVIRONMENT.md`
- README entry point: `README.md`
- Agent's context-cache, helper scripts & snippets: store under `.codex/`
- Tool registry: `.codex/README.md`
- Style guide: `dev/STYLEGUIDE.md`
- Documentation templates: `docs/assets/template.md` and `dev/manual/assets/template.md`

## Review Guidelines
- Ensure `dev/WORKLOG.md` session notes and TODOs reflect the change set.
- Check documentation updates adhere to `dev/STYLEGUIDE.md` (headings, tone, status tags) and leverage templates when applicable.
- Verify class map entries (`dev/CLASSMAP.md`) for new/modified callables; include test references.
- Confirm PR checklist items are addressed (testing, documentation, screenshots, security notes).
- Flag any drift between code and feature drafts (`dev/draft/*.md`) and update or log follow-ups.
- Review tests (unit/integration/UI) for completeness and determinism; ensure coverage for new logic.
- Review translation coverage. Replace every user-facing string with apropriate translation keys and add German and English translation. Logs and CLI-Output dont need to be localzed. Focus on rendered Twig output. Use `.codex/render.php /<route>` if you want to review a specific route's rendered output. Use `.codex/compare_translations.php` to compare available keys between `translations/messages.en.yaml` and `translations/messages.de.yaml` and report missing keys in either of these files.
- Revisit every markdown-file in `docs/` and check for coverage and completeness. Eliminate gaps when possible (also review `dev/manual/`). Make sure, documentation aligns with code changes and also check for gaps between existing documentation and codebase.
- Run or schedule Markdown link checks; report broken references and update docs during review when possible.