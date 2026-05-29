# Framework version recap

> **Status:** Active  
> **Updated:** 2026-05-29  
> **Owner:** Codex  
> **Purpose:** Offline working notes for this repository's framework and bundle versions, based on official documentation checks. Use this before implementing Symfony, Doctrine, Twig, frontend, or test changes.  

## Project baseline

The local `vendor/` directory is not installed in this workspace at the time of writing, so versions were taken from `composer.json`, `composer.lock`, `symfony.lock`, `importmap.php`, and repository configuration.

| Package or tool | Project version/constraint | Notes |
|-----------------|----------------------------|-------|
| PHP | `>=8.4` | Use strict types for PHP code. |
| Symfony components | `8.1.*` | Prefer Symfony 8.1 docs unless a feature is verified in the lockfile. |
| Doctrine DBAL | `4.4.3` | Use DBAL 4 APIs and prepared statements. |
| Doctrine ORM | `3.6.7` | Attribute mapping is the default project style. Avoid removed annotation-era patterns. |
| DoctrineBundle | `3.2.2` | Existing config uses attribute mapping for `App\Entity`. |
| DoctrineMigrationsBundle | `4.0.0` | Multiple migration paths/namespaces are supported through bundle configuration. |
| Twig | `3.27.0` | Avoid Twig 4-deprecated APIs where possible. |
| SymfonyCasts TailwindBundle | `0.12.0` | Project uses Tailwind binary `v4.1.11`. |
| Tailwind CSS | `v4.1.11` | CSS-first configuration. `assets/styles/app.css` uses `@import "tailwindcss";` and `@custom-variant`. |
| StimulusBundle | `3.1.0` | Project uses Symfony UX Stimulus loader plus importmap. |
| Turbo | `8.0.23` via importmap | Use Hotwire/Turbo 8 behavior; test form/navigation interactions. |
| Stimulus | `3.2.2` via importmap | Use controllers, targets, values, classes, and actions. |
| PHPUnit | `13.1.13` | Official online manual search currently surfaced PHPUnit 12.5 docs; avoid features documented as incompatible with PHPUnit 13. |

## Symfony 8 implementation notes

Checked official Symfony docs for AssetMapper, service tags, service decoration, EventDispatcher, Messenger, Rate Limiter, Mailer, Security, Routing, Validator, Translation, and TwigBundle configuration.

### General application structure

- Keep using Symfony's normal application model: controllers, services, forms, validators, voters, event subscribers, message handlers, Twig templates, AssetMapper assets, Doctrine entities/repositories, and console commands.
- Prefer PHP attributes for routes and Doctrine mapping where practical.
- Route priority is available for attribute routes; higher priority routes match first.
- Keep route prefixes explicit for admin, API, modules, assets, and content catch-all behavior.
- Use `config/packages/*.yaml` for framework/bundle configuration and `.env.local*` or secrets for local values.

### Dependency injection and extension points

- Use autowiring and constructor injection.
- Use tagged services for additive extension points. Symfony supports priority on tagged service collections.
- `AutoconfigureTag` and `AutowireIterator` are useful for Symfony-native plugin-style collections.
- Use service decoration for wrapping/replacing selected services when replacement is explicit.
- Do not invent custom registries before tagged services, decorators, or configuration have been considered.

### Events and Messenger

- Use EventDispatcher for synchronous lifecycle hooks and documented extension events.
- Public events must be documented; undocumented events remain internal to their owning feature.
- Use Messenger for asynchronous work, retries, mail sending, notifications, indexing, imports, backup jobs, and other delayed side effects.
- Current `messenger.yaml` already defines `async`, `failed`, retry strategy, failure transport, and async routing for Mailer/Notifier messages.
- Messenger retry strategy supports max retries, delay, multiplier, max delay, jitter, or a custom retry strategy service.
- For permanent handler failures, Symfony Messenger supports unrecoverable failure handling; use this when retrying cannot help.
- Message handlers should be idempotent when retries are possible.

### Forms, validation, CSRF, and recoverable errors

- Use Symfony Forms for browser-facing admin input where possible.
- Use Validator constraints for declarative validation. Symfony 8 includes useful constraints such as `Json`, `Yaml`, `ExpressionSyntax`, `Twig`, `Ulid`, `Uuid`, `NoSuspiciousCharacters`, `PasswordStrength`, `Collection`, `Sequentially`, `When`, and `UniqueEntity`.
- Use custom validators for manifest, schema, import, and theme validation where domain context is needed.
- Use CSRF protection for state-changing browser workflows.
- Keep recoverable workflow failures as result states or form errors, not generic exceptions.
- Keep user-facing validation text translated.

### Security and rate limiting

- Use Symfony Security configuration, voters, access rules, and CSRF rather than custom auth plumbing.
- Symfony login throttling and application-level rate limiting use the Rate Limiter component.
- Symfony RateLimiter is application-level and runs after Symfony boots. It is not a replacement for server/proxy-level DoS protection.
- Use voters for content, module, editor, and operational permissions.
- Captcha replacement should be an explicit provider/resolver contract, not hidden event mutation.

### Mailer and notifications

- Use Symfony Mailer for outbound email.
- With Messenger routing, Mailer messages can be sent asynchronously.
- The project already routes `Symfony\Component\Mailer\Messenger\SendEmailMessage` to `async`.
- Use Symfony's mailer test assertions in functional tests where available.
- Do not expose transport exceptions directly to users; log safe context.

### Translation

- Keep deterministic translation keys in synchronized English and German catalogues.
- Symfony supports ICU MessageFormat with `+intl-icu` catalogue suffixes when ICU syntax is needed.
- Existing project rules keep translation sources modular under `translations/languages/{locale}/*.yaml`; generated runtime catalogues use Symfony's default `messages` domain, and source catalogue pairs stay synchronized with `.codex/compare_translations.php`.

### AssetMapper, Importmap, Stimulus, and Turbo

- AssetMapper maps and versions files under `assets/`.
- Importmap entrypoints can be page-specific; call `importmap()` once per page.
- Use `php bin/console asset-map:compile` for production AssetMapper output.
- Use Stimulus controllers for progressive enhancement and small interactive features.
- Use Turbo carefully around forms, redirects, flash messages, and CSRF-protected workflows; verify behavior in rendered/browser tests when forms are involved.

## Tailwind CSS v4 and TailwindBundle notes

Checked official Tailwind v4 upgrade docs and SymfonyCasts TailwindBundle docs.

- This project uses Tailwind CSS `v4.1.11` via `symfonycasts/tailwind-bundle`.
- Tailwind v4 is CSS-first. Use `@import "tailwindcss";` in CSS, which the project already does in `assets/styles/app.css`.
- Tailwind v4 uses CSS directives such as `@theme`, `@utility`, `@custom-variant`, `@plugin`, `@source`, and `@config`.
- JavaScript `tailwind.config.js` is still supported for backward compatibility, but v4 no longer auto-detects it; load it explicitly with `@config` if needed.
- Official plugins in TailwindBundle's downloaded binary can be loaded in v4 with `@plugin`, for example typography.
- Tailwind v4 targets modern browsers. The official upgrade guide lists Safari 16.4+, Chrome 111+, and Firefox 128+ as the baseline. If older browsers become a requirement, reassess Tailwind v4 usage.
- Avoid old v3 assumptions:
  - Do not use `@tailwind base; @tailwind components; @tailwind utilities;` for new v4 code.
  - Do not assume a `content` array in JS config controls scanning unless explicitly using `@config`.
  - Do not rely on `resolveConfig()` for theme values in JavaScript; use CSS variables when possible.
- Use `php bin/console tailwind:build` after Tailwind/CSS changes.

## Doctrine notes

Checked official Doctrine ORM 3.6, DBAL 4.4, DoctrineBundle, and DoctrineMigrationsBundle docs.

### ORM 3.6

- Use PHP attribute mapping under `src/Entity`, matching existing DoctrineBundle config.
- Use `Doctrine\DBAL\Types\Types` constants for explicit column types.
- Doctrine can infer common mapping details from typed properties, but explicit types are clearer for durable schema.
- Doctrine supports PHP enum mapping through `enumType`; use it for domain states where stable.
- Avoid old annotation-style assumptions. Attribute mapping is current and compatible with ORM 3.
- Keep entities focused. For complex content schemas, use services/DTOs around entities instead of pushing all logic into entities.

### DBAL 4.4

- Use prepared statements and parameter binding for raw SQL.
- DBAL `QueryBuilder` supports select/insert/update/delete construction, but it is not a free SQL sanitizer. Treat dynamic SQL fragments carefully.
- Prefer ORM repositories for entity queries and DBAL for lower-level operational or schema-adjacent queries.
- Be cautious with DBAL 2-era examples from old blog posts; APIs and deprecations changed.

### Migrations

- DoctrineMigrationsBundle supports configured migration paths/namespaces.
- Core migrations should remain in the main application migration flow.
- Module migrations can be modeled as additional paths/namespaces declared through module manifests, but execution should remain core-owned and validated.
- Avoid ad-hoc module database mutations outside the migration workflow.

## Twig 3.25 notes

Checked official Twig 3 deprecated-features and Symfony TwigBundle configuration docs.

- Avoid the deprecated `spaceless` filter in new templates.
- If creating custom Twig functions/filters/tests, use modern Twig callable APIs and avoid deprecated options like `deprecated`, `deprecating_package`, and `alternative`; use `deprecation_info` when deprecating custom callables.
- Do not pass `Twig\Template` instances to Twig public APIs when `TemplateWrapper` is expected.
- Keep Twig templates small and use Twig extensions/components only for behavior that belongs there.
- Use TwigBundle `paths`, `form_themes`, globals, and strict variable configuration intentionally when implementing the theme engine.

## PHPUnit notes

- Project lockfile uses PHPUnit `13.1.12`.
- PHPUnit docs found during this pass prominently expose 12.5 as current public manual pages. Treat PHPUnit-13-specific behavior as needing verification before relying on new APIs.
- PHPUnit supports `test*` method naming and `#[Test]` attributes.
- Prefer explicit, deterministic tests with small fixtures.
- Avoid old mock/stub patterns flagged as incompatible with PHPUnit 13. The PHPUnit 12.5 docs note that using `with()` on a test stub no longer works in PHPUnit 13.
- Keep tests under `tests/` mirroring production namespaces.

## Frontend library notes

### Stimulus 3

- Use controllers for behavior attached through `data-controller`.
- Use actions through `data-action`.
- Use targets, values, classes, and outlets instead of manual DOM querying where this keeps controllers clearer.
- Keep controllers small and disconnect cleanly when they create external instances such as charts or editors.

### Turbo 8

- Turbo can change navigation and form submission behavior. Validate CSRF, redirects, flash messages, and validation error rendering when Turbo is active.
- Use Turbo features deliberately; do not assume ordinary full-page form behavior without testing.

### CodeMirror 6

- Project importmap includes CodeMirror 6 packages and language packages.
- CodeMirror 6 is modular. Use only needed extensions/languages and destroy editor instances on Stimulus disconnect.

### ApexCharts 5

- Project importmap includes ApexCharts `5.12.0`.
- Keep charts lazy and destroy chart instances on disconnect, matching the existing controller pattern.

## Source links checked

- Symfony 8 AssetMapper: https://symfony.com/doc/8.0/frontend/asset_mapper.html
- Symfony 8 service tags: https://symfony.com/doc/8.0/service_container/tags.html
- Symfony service decoration: https://symfony.com/doc/current/service_container/service_decoration.html
- Symfony 8 EventDispatcher: https://symfony.com/doc/8.0/event_dispatcher.html
- Symfony 8 Messenger: https://symfony.com/doc/8.0/messenger.html
- Symfony 8 Rate Limiter: https://symfony.com/doc/8.0/rate_limiter.html
- Symfony Security: https://symfony.com/doc/current/security.html
- Symfony 8 Mailer: https://symfony.com/doc/8.0/mailer.html
- Symfony 8 Validation constraints: https://symfony.com/doc/8.0/reference/constraints.html
- Symfony Routing: https://symfony.com/doc/current/routing.html
- Symfony 8 Translation: https://symfony.com/doc/8.0/translation.html
- SymfonyCasts TailwindBundle: https://symfony.com/bundles/TailwindBundle
- Tailwind CSS v4 upgrade guide: https://tailwindcss.com/docs/upgrade-guide
- Tailwind CSS v4 announcement: https://tailwindcss.com/blog/tailwindcss-v4
- Doctrine ORM 3.6 attributes: https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/attributes-reference.html
- Doctrine ORM 3.6 basic mapping: https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/basic-mapping.html
- Doctrine DBAL 4.4 QueryBuilder: https://www.doctrine-project.org/projects/doctrine-dbal/en/4.4/reference/query-builder.html
- Doctrine DBAL 4.4 security: https://www.doctrine-project.org/projects/doctrine-dbal/en/4.4/reference/security.html
- DoctrineMigrationsBundle: https://symfony.com/doc/current/DoctrineMigrationsBundle/index.html
- Twig 3 deprecated features: https://twig.symfony.com/doc/3.x/deprecated.html
- Symfony TwigBundle configuration: https://symfony.com/doc/current/reference/configuration/twig.html
- Stimulus handbook: https://stimulus.hotwired.dev/handbook/introduction
- Turbo handbook: https://turbo.hotwired.dev/handbook/introduction
- PHPUnit manual: https://docs.phpunit.de/
