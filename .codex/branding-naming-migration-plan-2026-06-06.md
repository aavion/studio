# Branding-Neutral Naming Migration Plan 2026-06-06

> **Status**: Draft for implementation  
> **Branch**: `audit-project-readiness`  
> **Base context**: Visitor identity slice committed as `c2a86a1 Harden visitor identity tracking`.  
> **Purpose**: Migrate branding-irrelevant `studio` identifiers toward neutral domain names, `system` owner names, or package-slug-owned names before `1.0.0`.

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
     - `studio:assets:rebuild` -> `assets:rebuild`
     - `studio:packages:discover` -> `packages:discover`
     - `studio:packages:assets:sync` -> `packages:assets:sync`
     - `studio:packages:lifecycle` -> decide between `packages:lifecycle` and a clearer operation name.
     - `studio:operations:run` -> `operations:run`
     - `studio:operations:cleanup` -> `operations:cleanup`
     - `studio:statistics:snapshot` -> `statistics:snapshot`
     - `studio:scheduler:run` -> `scheduler:run`
     - `studio:account-tokens:cleanup` -> decide whether `account-tokens:cleanup` or `security:account-tokens:cleanup` is clearer.
     - `studio:acl-groups:apply` -> decide whether `acl-groups:apply` is clear enough.
   - Update setup subprocesses, Scheduler task definitions, Live Operation command invocations, runner process inspection, docs, and tests in the same slice.

4. **Log file names**
   - Move from `%env%.system-{message|audit|access}-*.log` to `{APP_ENV}/{message|audit|access}-*.log` where Monolog rotation supports the target shape.
   - Update `LogSourceRegistry`, Admin Logs tests, controller tests, setup script tests, and docs.
   - Keep Monolog channel names technical and stable; decide whether channel names should be `message`/`audit`/`access` or remain `system_*` for logger-service disambiguation.

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
- `studio:packages:lifecycle` becomes `packages:lifecycle`.
- Account and ACL commands become `account-tokens:cleanup` and `acl-groups:apply` unless implementation reveals a clearer shared pattern.
- Monolog channels and filenames should both use speaking names where practical; the Admin Log viewer should not show internal owner prefixes because they add no user value.

## Verification Plan

- Use `git diff --check -- . ':(exclude,glob)**/*.md' ':(exclude,glob)*.md'`.
- Run focused lint for changed PHP/YAML/Twig/CSS files.
- Run `php bin/console lint:container` after command/service/log changes.
- Run focused tests for Twig helpers, package template path validation, CLI commands, setup runner, scheduler, live operations, admin logs, and public rendering.
- Run broader `bin/lint` and targeted controller tests before committing the slice.
