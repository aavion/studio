# Setup and init snippets

> **Status**: Draft  
> **Updated**: 2026-05-23  
> **Owner**: Core  
> **Purpose:** Capture setup and init behavior notes before the first-run installer and automation workflows are finalized.  

## Overview

`bin/init` prepares the repository for automated workflows. `bin/setup` is a placeholder for later first-run application setup.

## Init responsibilities

`bin/init` should:

- run without vendor dependencies already installed;
- verify PHP version and required extensions;
- resolve Composer through system Composer or `bin/composer`;
- install production dependencies first;
- resolve Symfony environment consistently with Symfony's dotenv behavior;
- install development dependencies for `dev` and `test`;
- rely on Composer auto-scripts for ImportMap, public assets, and Tailwind;
- run AssetMapper compilation only for `prod`;
- return clear success, warning, and failure summaries.

## Environment note

Symfony environment resolution should match Symfony precedence as closely as practical. Real environment variables win over values loaded from `.env*` files.

## Setup responsibilities

`bin/setup` should remain separate from `bin/init`. Later setup can handle:

- installation data collection;
- database configuration checks;
- secret generation;
- admin account creation;
- initial content or demo data;
- persistence preparation;
- setup action logs.

## Automation notes

Automation workflows should call `bin/init` before reviews or tests when a fresh checkout may not have dependencies or built assets.

`bin/init` should not mutate application data or ask interactive setup questions.

## Test suite lifecycle note

`App\Tests\Support\TestSuiteLifecycle` is already wired into `tests/bootstrap.php`, but it is intentionally light. It should stay available for later demo/test setup that must run once per PHPUnit process, while ordinary tests should continue to prefer isolated temporary directories.

Potential future uses:

- prepare reusable generated fixtures;
- create a demo database snapshot;
- warm package import caches;
- clean shared test artifacts after shutdown.

## References

- [Core architecture snippets](core-architecture-snippets.md)
- [Test fixture snippets](test-fixture-snippets.md)
- [Setup and test automation draft](../draft/0.1.x-SetupTestAutomation.md)
- `bin/init`
- `bin/setup`
- `tests/Support/TestSuiteLifecycle.php`
