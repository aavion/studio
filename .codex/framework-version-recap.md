# Framework And Dependency Recap

> **Status:** Active  
> **Updated:** 2026-06-13  
> **Owner:** Codex  
> **Purpose:** Cache current project dependency notes so agents avoid older Symfony, Doctrine, Twig, Tailwind, PHPUnit, CommonMark, and Symfony UX habits between documentation checks.  

## Cache Policy

Use this recap as a local, version-pinned documentation cache before routine Symfony, Doctrine, Twig, Tailwind, PHPUnit, CommonMark, and Symfony UX work. Refresh the relevant section from Context7 or official documentation when installed versions change, when a task depends on precise current API behavior, when the note is unclear, or when new documentation findings would prevent future outdated habits. Keep newly useful findings in this file while the versions still match.

## Project Baseline

Versions were checked against the installed Composer packages, `composer.json`, `composer.lock`, `importmap.php`, and local configuration.

| Package or tool | Installed version or constraint | Notes |
|-----------------|---------------------------------|-------|
| PHP | `>=8.4.1`; local CLI 8.5.7 | Keep `declare(strict_types=1);` and PHP 8.4-compatible project code unless a PHP 8.5 feature is explicitly approved. |
| Symfony components | `8.1.0` | Prefer Symfony 8.x docs and native components before custom infrastructure. |
| Doctrine ORM | `3.6.7` | Attribute mapping is the project style. |
| Doctrine DBAL | `4.4.3` | Use DBAL 4 result and statement APIs. |
| DoctrineBundle | `3.2.4` | Existing configuration uses attribute mapping for `App\Entity`. |
| DoctrineMigrationsBundle | `4.0.0` | Keep pre-`1.0.0` migrations consolidated through the current baseline migration. |
| Twig | `3.27.1` | Avoid Twig 4-deprecated APIs in new code. |
| SymfonyCasts TailwindBundle | `0.13.0` | Project pins Tailwind CLI `v4.3.0`. |
| Tailwind CSS | `v4.3.0` binary | CSS-first configuration. |
| StimulusBundle | `3.1.0` | AssetMapper integration is active. |
| Symfony UX packages | `3.1.0` | Autocomplete, CalendarLink, Chartjs, Cropperjs, Dropzone, Icons, LiveComponent, Map, Native, Notify, React, Translator, Turbo, TwigComponent, and Vue are installed. |
| MercureBundle / Mercure Notifier | `0.8.0` / `8.1.0` | Used by UX Turbo Streams/Notify foundations; default local URLs derive from `DEFAULT_URI`. |
| UX Turbo | `3.1.0`; Turbo `8.0.23` via importmap | Turbo Drive is available; use deliberately around forms. |
| Stimulus | `3.2.2` via importmap | Use values, targets, classes, actions, outlets, and lifecycle cleanup. |
| League CommonMark | `2.8.2` | Use GFM/CommonMark converters for Markdown rendering and lint parse smoke checks. |
| PHPUnit | `13.2.0` | Use PHPUnit 13 docs. Avoid deprecated PHPUnit 12-era assertions and PHPUnit 14-deprecated double habits. |

## Symfony 8.1 Notes

- Prefer constructor injection, autowiring, autoconfiguration, tagged services, service decoration, voters, Messenger, Validator, Form, Serializer, Translation, AssetMapper, Process, Lock, Scheduler, and EventDispatcher before custom infrastructure.
- Use `#[AutowireIterator]`, tagged iterators, and `#[AutoconfigureTag]` for additive extension points. Keep central registries as aggregators or validators, not hand-built service locators.
- Keep request-dependent services out of constructors where possible. Inject `RequestStack` only into boundary services that truly need request context.
- Public events and hooks must be domain-owned and documented. Internal events may stay implementation details.
- Messenger messages and handlers should be idempotent when retries are possible. Use `UnrecoverableMessageHandlingException` or explicit failure states for failures that retries cannot fix.
- Scheduler work should stay Messenger-aware and lock-aware; avoid duplicate custom process runners when a Scheduler/Messenger pattern fits.
- Validator messages should use translation keys. Symfony supports modern constraints such as `Json`, `Yaml`, `ExpressionSyntax`, `Twig`, `Ulid`, `Uuid`, `NoSuspiciousCharacters`, `PasswordStrength`, `Collection`, `Sequentially`, and `When`.
- ICU MessageFormat catalogues require `+intl-icu` file suffixes. The project currently keeps modular source catalogues under `translations/languages/{locale}/*.yaml` and generates runtime `messages.*.yaml`.
- Mailer messages can route through Messenger. Do not expose transport exceptions directly to users; map recoverable delivery problems into Message-layer feedback and safe logs.
- Process calls should use the project process/environment helpers so Symfony Dotenv-derived configuration is inherited and web/CGI context is filtered.

## Doctrine Notes

### ORM 3.6

- Use PHP attributes under `src/Entity`; avoid annotation-era examples.
- Use typed properties, explicit column metadata where clarity helps, and `Doctrine\DBAL\Types\Types` constants for durable schema definitions.
- Use `enumType` for stable PHP-backed domain states.
- Keep entities focused. Put orchestration, validation policy, cross-aggregate coordination, and read-model shaping in services or repositories.
- Prefer repositories and DQL/ORM QueryBuilder for entity reads; avoid full-table PHP filtering for paginated or ACL-sensitive lists.

### DBAL 4.4

- Use `Connection::executeQuery()` for reads and `Connection::executeStatement()` for writes. Do not use old `execute()` habits from DBAL 2/3 examples.
- Use `prepare()` only when a statement is intentionally reused; otherwise use `executeQuery()` / `executeStatement()` with parameters and types.
- Treat QueryBuilder as a query construction helper, not a sanitizer for dynamic SQL fragments. Only parameter values are safely bound automatically.
- Use DBAL result APIs such as `fetchAssociative()`, `fetchOne()`, and `iterateAssociative()` instead of older statement-fetch patterns.
- Keep vendor-specific SQL, JSON operators, and index assumptions out of core read paths unless the feature explicitly declares a database/version requirement.

### Migrations

- Before `1.0.0`, update the current baseline migration instead of growing a long development migration chain.
- Keep entities, baseline migration, tests, docs, and class map aligned when database shape changes.
- Package/module migrations may be planned as validated package contributions, but execution should remain core-owned.

## Twig 3.27 Notes

- Use `TwigFunction`, `TwigFilter`, and `TwigTest` with modern options. Avoid deprecated options such as `deprecated`, `deprecating_package`, and `alternative`; use `deprecation_info` for custom callable deprecations.
- Avoid the deprecated `spaceless` filter; use whitespace-aware template structure or CSS instead.
- Do not call old internal Twig extension functions such as `twig_escape_filter()` directly. Use runtime APIs such as `EscaperRuntime` where lower-level escaping is genuinely needed.
- Do not pass `Twig\Template` where public APIs expect `TemplateWrapper`.
- Keep templates small; move behavior to Twig extensions, components, or services only when the boundary is clear.
- Use TwigBundle `paths`, `form_themes`, globals, and strict variable settings intentionally for package/theme/template work.

## Tailwind CSS v4 And TailwindBundle Notes

- The project uses `symfonycasts/tailwind-bundle` with `binary_version: 'v4.3.0'` and `input_css: 'assets/styles/app.css'`.
- Tailwind v4 is CSS-first. Use `@import "tailwindcss";`, `@theme`, `@utility`, `@custom-variant`, `@plugin`, `@source`, and `@config` in CSS.
- Avoid v3 habits for new code:
  - Do not add `@tailwind base;`, `@tailwind components;`, or `@tailwind utilities;`.
  - Do not assume a JavaScript `content` array drives scanning unless an old config is explicitly loaded with `@config`.
  - Do not rely on `resolveConfig()` for JavaScript theme access; prefer CSS variables.
- Tailwind v4 targets modern browsers. Reassess if older browser support becomes a product requirement.
- Use `php bin/console tailwind:build` after Tailwind/CSS changes. Focused CSS syntax lint may not understand Tailwind-specific at-rules; Tailwind build is authoritative.

## Symfony UX Notes

### Installed UX Packages

- `symfony/stimulus-bundle` plus the Symfony UX 3.1 package set are installed: Autocomplete, CalendarLink, Chartjs, Cropperjs, Dropzone, Icons, LiveComponent, Map, Native, Notify, React, Translator, Turbo, TwigComponent, and Vue.
- The project uses AssetMapper/importmap, not a Node build pipeline.
- `@symfony/stimulus-bundle`, `@hotwired/stimulus`, and `@hotwired/turbo` are in `importmap.php`.
- `assets/controllers.json` keeps optional UX Stimulus controllers lazy by default. Leave expensive controllers lazy until a template actually references them.
- React and Vue use the AssetMapper loader form of `registerReactControllerComponents()` and `registerVueControllerComponents()`; do not copy Webpack-era `require.context()` examples into this project.
- UX Icons has remote Iconify lookup disabled in `config/packages/ux_icons.yaml` so builds and CI stay offline-safe. `bin/init` and `assets:rebuild` run `ux:icons:lock` to import referenced icons into `assets/icons` when Iconify is reachable; failures are non-blocking warnings so offline CI and admin rebuilds do not fail only because remote icon lookup is unavailable.
- `bin/lint` validates static Twig icon references locally without network access or writes. It checks `ux_icon('...')` and `<twig:ux:icon name="...">` references against `assets/icons`, resolving configured aliases first; use `ux:icons:lock` only as the mutating import step.
- Commit locked SVGs under `assets/icons` as reviewable dependency snapshots. Avoid committing complete upstream icon sets by default; let the set grow from real template usage and explicit aliases.

### StimulusBundle

- Bootstrap through `startStimulusApp()` from `@symfony/stimulus-bundle` when touching the Stimulus boot path.
- Use `stimulus_controller()`, `stimulus_action()`, and `stimulus_target()` helpers when generating complex controller attributes from Twig.
- Mark expensive UX package controllers as lazy through `assets/controllers.json` with `"fetch": "lazy"` when they are not needed on every page.
- Use Stimulus values, targets, classes, actions, and outlets before manual DOM querying. Destroy charts/editors/listeners in `disconnect()`.
- The old `ux_controller_link_tags()` Twig function is removed in Symfony UX 3; AssetMapper handles controller assets.

### Turbo

- Turbo Drive is enabled by default when UX Turbo is active. Verify CSRF, redirects, validation errors, flash messages, and retained scroll/focus behavior for forms.
- For successful non-GET forms, prefer redirect-after-post with `303 See Other` unless intentionally returning Turbo Streams.
- For Turbo Stream responses, check `TurboBundle::STREAM_FORMAT`, set the request format, and render only the stream/block response.
- In UX Turbo 3.1, `turbo_stream_listen()` and the old Mercure listen renderer are deprecated. Use `turbo_stream_from()` or the `<twig:Turbo:Stream:From>` component for Mercure-backed stream subscriptions.
- Use `<turbo-frame>` for scoped replacement, but ensure frame responses contain the expected frame or deliberately opt into full-page reload behavior.
- Disable Turbo for flows where browser-native behavior is required, for example logout or setup forms, using `data-turbo="false"`.

### Components And Rich UI

- TwigComponent registers PHP classes with `#[AsTwigComponent]`; public properties become props, components are services, and templates can use the special `attributes` variable with `attributes.defaults()`.
- Anonymous Twig components can be template-only and declare props with `{% props %}`. Use them for simple repeated UI fragments before adding PHP classes.
- LiveComponent builds on TwigComponent with `#[AsLiveComponent]`, `#[LiveProp]`, `#[LiveAction]`, `DefaultActionTrait`, form traits, URL-bound props, hydration/dehydration, and validation helpers.
- Use LiveComponent only for interactions that benefit from server-roundtrip reactivity. Keep plain Symfony forms, Stimulus, or Turbo Frames for simpler workflows.
- Use UX Autocomplete for entity/reference selections, Dropzone/Cropperjs for media workflows, Chartjs or the existing ApexCharts integration for dashboards, Icons for UI symbols, Translator for JavaScript copy, Notify for browser notifications, and Map only behind explicit provider/configuration choices.

### Mercure

- MercureBundle reads `MERCURE_URL`, `MERCURE_PUBLIC_URL`, and `MERCURE_JWT_SECRET`. The committed defaults derive URLs from `DEFAULT_URI` and the development JWT secret from `APP_SECRET`; production deployments should override these with environment-specific hub URLs and secrets when the hub is external or separately rotated.
- Keep `MERCURE_DSN=mercure://default` for notifier integration unless a real notification transport strategy says otherwise.

## CommonMark Notes

- Use `League\CommonMark\GithubFlavoredMarkdownConverter` for GitHub-Flavored Markdown and `CommonMarkConverter` for baseline CommonMark.
- Pass security options explicitly for untrusted Markdown, especially `html_input` and `allow_unsafe_links`.
- The project uses CommonMark/GFM parsing in `bin/lint` as a Markdown parse/render smoke check; this is not a strict Markdown style linter.

## PHPUnit 13 Notes

- Use PHPUnit 13 docs, not PHPUnit 10/11/12 examples.
- Test methods may use `test*` names or the `#[Test]` attribute.
- Use `createStub()` when no interaction verification is needed and `createMock()` when expectations matter.
- `with()` is valid with `expects()` for method argument verification, but using `with()` without `expects()` is deprecated and will be removed in PHPUnit 14.
- The `any()` matcher is deprecated and will be removed in PHPUnit 14; use stubs when call counts are not relevant.
- Avoid assertions deprecated in PHPUnit 12 and removed in PHPUnit 13, such as native-type `assertContainsOnly()` / `assertNotContainsOnly()` patterns.
- Keep fixtures deterministic and clean up filesystem/database state explicitly.

## Frontend Library Notes

- CodeMirror 6 is modular; import only needed extensions/languages and destroy instances on Stimulus disconnect.
- ApexCharts 5 should remain lazy and be destroyed on Stimulus disconnect.
- Alpine.js is available through importmap but should not become a second dominant interaction model next to Stimulus/Turbo without an explicit reason.

## Documentation Sources Checked

- Context7 `/symfony/symfony-docs/__branch__8.0`, `/websites/symfony_doc_8_1`
- Context7 `/doctrine/orm`, `/websites/doctrine-project_projects_doctrine-orm_en`, `/doctrine/dbal`
- Context7 `/websites/twig_symfony_doc`
- Context7 `/tailwindlabs/tailwindcss.com`, `/symfonycasts/tailwind-bundle`
- Context7 `/symfony/ux`, `/symfony/stimulus-bundle`, `/websites/symfony_bundles_ux-turbo_current`, `/symfony/ux-twig-component`, `/symfony/ux-live-component`
- Context7 `/symfony/symfony-docs/__branch__8.0` for MercureBundle configuration
- Context7 `/thephpleague/commonmark`
- Context7 `/websites/phpunit_de_en_13_0`
