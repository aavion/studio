# Setup and init snippets

> **Status**: Draft  
> **Updated**: 2026-05-24  
> **Owner**: Core  
> **Purpose:** Capture setup and init behavior notes before the first-run installer and automation workflows are finalized.  

## Overview

`bin/init` prepares the repository for automated workflows. `bin/setup` performs first-run application setup through the shared `App\Setup\SetupRunner` service.

## Init responsibilities

`bin/init` should:

- run without vendor dependencies already installed;
- verify PHP version and required extensions;
- resolve Composer through system Composer or `bin/composer`;
- remove an existing `vendor/` tree before Composer install so corrupt vendor packages cannot poison dependency resolution;
- install production dependencies first;
- generate core-only runtime translation catalogues from `translations/languages/{locale}` before Symfony console consumers run;
- resolve Symfony environment consistently with Symfony's dotenv behavior;
- install development dependencies for `dev` and `test`;
- rely on Composer auto-scripts for ImportMap, public assets, and Tailwind;
- run AssetMapper compilation only for `prod`;
- return clear success, warning, and failure summaries.

## Environment note

Symfony environment resolution should match Symfony precedence as closely as practical. Real environment variables win over values loaded from `.env*` files.

## Setup responsibilities

`bin/setup` remains separate from `bin/init` and assumes `bin/init` already generated the core runtime translation catalogues. Setup currently handles:

- installer language selection from generated translation catalogues with source-directory fallback;
- translated interactive CLI prompts when `bin/setup` runs in a TTY;
- site title and default URL values;
- database URL compilation for SQLite, MySQL/MariaDB, and PostgreSQL;
- secret generation;
- admin account creation;
- optional password recovery with `bin/setup --reset-password={username}` or `bin/setup --reset-password:{username}`;
- env override writing and `composer dump-env`;
- PHP CLI preference resolution through `APP_DEFAULT_PHP_BINARY` with validation before use;
- Doctrine migration execution;
- database-backed default settings, including `localization.default_language`, disabled `localization.route_prefixes_enabled`, and `content.home_path`;
- a minimal locked `static_page` schema plus published `/home` placeholder page so the configured public root can render immediately after setup;
- dry-run planning without writing env files, running commands, or seeding the database;
- setup action logs with halt-on-error results.

After migrations and initial data seeding, setup clears the cache and then runs two serial subprocesses in order: `packages:discover --run-now --trigger=setup`, then `assets:rebuild --trigger=setup --json`. This keeps cold setup memory bounded per process while still allowing system-default active packages to contribute assets and translations after the package registry is available. Setup asks the asset rebuild for JSON output so non-blocking rebuild warnings can be surfaced in the setup action log. `bin/init` still generates core-only runtime catalogues before Symfony console consumers run; setup runs the package-aware rebuild afterwards so active package translations can be aggregated once the database is initialized.

Setup subprocesses provide a local `COMPOSER_HOME` under `var/composer-home` when no explicit Composer home is present, and fall back to `var` as `HOME` when the web server environment omits it. This keeps web setup compatible with Composer without relying on shell-only environment variables.

Setup and operational subprocesses resolve PHP CLI through a cache-first manager. `APP_DEFAULT_PHP_BINARY` in `.env.{APP_ENV}.local` is treated as a preference, not as hardcoded truth: it is validated for CLI SAPI, project PHP version, required extensions, and project `bin/console` readability before use. If the stored binary is missing or no longer meets requirements, controlled setup, preflight auto-heal, live-operation, scheduler, Messenger-drain, cache-clear, and asset-rebuild flows fall back to the resolver and refresh the preference when a reusable binary value is found. Absolute binary paths and PATH-based values such as `php` are both acceptable when they validate; environments with well-maintained PATH values should not be forced into absolute-path pinning. Child processes inherit Symfony Dotenv-provided application values from the current process, while web/CGI request variables such as `HTTP_*`, `SERVER_*`, and request metadata are filtered out. Detached Messenger-drain startup and SQLite setup database URLs are handled with platform-specific path/process details so the same setup flow can run on Unix-like hosts and Windows hosts.

Use `--no-interaction` for scripted CLI setup with defaults and explicit options. `--json` is also non-interactive so automation receives machine-readable output only.

Interactive CLI setup asks for the admin password twice. Non-interactive setup uses the provided `--admin-password` value directly. Setup, registration, profile password changes, and reset flows enforce the shared password policy: at least 8 characters, at least three character types, no character repeated more than three times in a row, and no username or email local-part inside the password. Password reset displays the matched user's UID, username, email, and status before prompting for confirmation and the new password; scripted reset runs should pass `--confirm` and `--new-password`.

## Automation notes

Automation workflows should call `bin/init` before reviews or tests when a fresh checkout may not have dependencies or built assets.

When Composer packages look incomplete, corrupted, or inconsistent, run `bin/init` as the first recovery path. It removes the existing `vendor/` tree before Composer runs, then restores production dependencies for bootstrap and development dependencies for local `dev` or `test` workflows.

`bin/init` should not mutate application data or ask interactive setup questions.

## Test suite lifecycle note

`App\Tests\Support\TestSuiteLifecycle` is wired into `tests/bootstrap.php`. It owns `env:test` database setup, rejects concurrent PHPUnit processes with a non-blocking lock, clears `var/test`, applies migrations, and seeds deterministic demo data once per PHPUnit process. It also keeps shutdown cleanup available for shared test artifacts. Ordinary filesystem tests should continue to prefer isolated temporary directories.

## References

- [Core architecture snippets](core-architecture-snippets.md)
- [Test fixture snippets](test-fixture-snippets.md)
- [Setup and test automation draft](../draft/0.1.x-SetupTestAutomation.md)
- `bin/init`
- `bin/setup`
- `tests/Support/TestSuiteLifecycle.php`
