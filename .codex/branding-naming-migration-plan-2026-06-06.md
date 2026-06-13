# Branding-Neutral Naming Migration Plan 2026-06-06

> **Status**: Completed / historical  
> **Updated**: 2026-06-12  
> **Branch**: `audit-project-readiness`  
> **Base context**: Visitor identity slice committed as `c2a86a1 Harden visitor identity tracking`.  
> **Purpose**: Preserve the completed migration plan for branding-irrelevant `studio` identifiers.

## Completion Note

This plan is no longer active implementation guidance. The second readiness audit recorded the final naming scan: remaining `Studio`, `studio_`, or `studio-*` hits are product/brand strings, database-prefix examples or fixtures, package scope values such as `system-template`, or historical audit notes. Native inspectable CSS, template, helper, CLI, and log-path names were migrated toward branding-neutral `system` or domain ownership.

Keep this file as historical context for why the naming rules exist. New naming decisions are governed by `AGENTS.md`.

## Product Decisions

- `Studio`/`studio` is branding, not the default technical namespace.
- Runtime branding should come from `.manifest`, branding assets, branding tokens, and theme/system-template overrides.
- `system` behaves like the native package slug. Use it where an owner/scope identifier is needed for package validation, shared namespaces, generated assets, cookies, session/browser storage, service tags, and provider/template/CSS ownership.
- Do not add a separate `branding` package scope for now. `system-template` is sufficient for root-template and branding-surface overrides if it can also influence rendering-time app display variables such as `APP_NAME`/manifest-derived name before Twig context is resolved.
- A package with `system-template` may override `branding.css`/branding tokens and root rendering context, but this must stay rendering-scoped and must not become a general runtime configuration write path.
- Plain domain names are preferred when ownership is obvious and no shared namespace needs protection, for example `navigation()`, `markdown`, `visitor_id`, `message`, `audit`, and `access`.

## CSS And Template Namespace Rule

- `@root` / native shared system templates: `system-*`.
- `@frontend`: `system-frontend-*`.
- `@backend`: `system-backend-*`.
- `@provider/{scope}`: `system-{provider-scope}-*`.
- Package-owned selectors mirror the same shape with the package slug:
  - `{package-slug}-*`
  - `{package-slug}-frontend-*`
  - `{package-slug}-backend-*`
  - `{package-slug}-{provider-scope}-*`
- Future package validation should reject package CSS/templates that claim another package slug, `system-*`, or a scope the package does not declare.

## Implementation Slices

1. **Twig helpers and templates**
   - Rename `studio_navigation()` to `navigation()`.
   - Rename `studio_markdown` filter to `render_markdown`.
   - Rename simple helper families to domain names where clear, for example `html_attributes()`, `request_trace()`, `macro_template()`, `macro_namespaces()`, `event_hooks()`, `backend_actions()`, `footer_copyright()`, `extension_packages()`, `themes()`, `package_setting*()`, and `core_settings_form()`.
   - Remove the unused `studio_view` global and rename `studio_view_context()` to `view_context()`.

2. **CSS classes and template IDs**
   - Replace native `studio-*` classes/IDs with `system-*`, `system-frontend-*`, `system-backend-*`, or provider-scoped names according to the template namespace.
   - Update tests that intentionally assert rendered classes, but avoid adding brittle selector assertions where behavior can be asserted more directly.
   - Keep actual UI copy using the manifest/app name, not CSS class names.

3. **CLI command names**
   - Prefer neutral Symfony-style command names:
     - `assets:rebuild`
     - `packages:discover`
     - `packages:assets:sync`
     - `packages:lifecycle`
     - `operations:run`
     - `operations:cleanup`
     - `statistics:snapshot`
     - `scheduler:run`
     - `account-tokens:cleanup`
     - `acl-groups:apply`
   - Update setup subprocesses, Scheduler task definitions, Live Operation command invocations, runner process inspection, docs, and tests in the same slice.

4. **Log file names**
   - Move from `%env%.system-{message|audit|access}-*.log` to `{APP_ENV}/{message|audit|access}-*.log` where Monolog rotation supports the target shape.
   - Update `LogSourceRegistry`, Admin Logs tests, controller tests, setup script tests, and docs.
   - Keep Monolog channel names as `message`, `audit`, and `access` so file names, parsed channels, and admin log labels stay aligned.

5. **System-template rebranding gate**
   - Verify current `system-template` packages can override root templates and branding assets sufficiently.
   - Add a rendering-time metadata/branding provider hook only if needed so an active `system-template` package can override display name/logo/token input before Twig context is resolved.
   - Keep the root `.manifest` as the default fallback source.

6. **Package validation follow-up**
   - After the native selector migration, add a validator pass for package-owned selectors and template namespace ownership.
   - Start with warnings if strict validation would block current fixtures, then promote to blocking before release readiness.

## Interview Candidates Before Editing

- `studio_view` is removed because no template needs a global provider object; `studio_view_context()` becomes `view_context()`.
- `studio_markdown` becomes `render_markdown` to keep the filter verb-based and avoid likely third-party `markdown` collisions.
- Backend/admin helper functions use speaking domain names such as `backend_actions()`, `core_settings_form()`, and `package_settings_form()`.
- `packages:lifecycle` is clear enough because it is an operator-facing lifecycle adapter and the package domain is already explicit.
- Account and ACL commands become `account-tokens:cleanup` and `acl-groups:apply` unless implementation reveals a clearer shared pattern.
- Monolog channels and filenames should both use speaking names; the Admin Log viewer should not show internal owner prefixes because they add no user value.

## Verification Plan

- Use `git diff --check -- . ':(exclude,glob)**/*.md' ':(exclude,glob)*.md'`.
- Run focused lint for changed PHP/YAML/Twig/CSS files.
- Run `php bin/console lint:container` after command/service/log changes.
- Run focused tests for Twig helpers, package template path validation, CLI commands, setup runner, scheduler, live operations, admin logs, and public rendering.
- Run broader `bin/lint` and targeted controller tests before committing the slice.
