# Developer Class Map

> **Status**: Active  
> **Updated**: 2026-05-22
> **Owner**: Core  
> **Purpose:** This document tracks callable entry points (services, commands, controllers, Twig components, Stimulus controllers). Keep it up to date as new classes are added or interfaces change. This document is meant to evolve alongside the codebase—treat it as a living index for developers to quickly discover callables without grepping through the project.

## 1. Services

| Service ID | Class | Description | Docs | Test-Class |
|------------|-------|-------------|------| ---------- |
| N/A | `App\Core\Manifest\Manifest` | Value object for parsed `.manifest` key-value metadata. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Manifest/ManifestParserTest.php`, `tests/Core/Manifest/ManifestValidatorTest.php` |
| N/A | `App\Core\Manifest\ManifestKey` | Shared manifest key syntax helper. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Manifest/ManifestParserTest.php`, `tests/Core/Manifest/ManifestSpecTest.php` |
| N/A | `App\Core\Manifest\ManifestParser` | Neutral parser for `.manifest` `KEY=VALUE` syntax without domain-specific required keys. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Manifest/ManifestParserTest.php` |
| N/A | `App\Core\Manifest\ManifestSpec` | Domain-neutral specification for required and allowed manifest keys. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Manifest/ManifestSpecTest.php`, `tests/Core/Manifest/ManifestValidatorTest.php` |
| N/A | `App\Core\Manifest\ManifestValidator` | Validates parsed manifests against a supplied manifest specification. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Manifest/ManifestValidatorTest.php` |
| N/A | `App\Core\Lint\CssLinter` | Reusable string-based CSS syntax linter using the strict Sabberworm CSS parser. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\JavaScriptLinter` | Reusable string-based JavaScript module syntax linter using Peast. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\JsonLinter` | Reusable string-based JSON syntax linter. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\LintIssue` | Value object for reusable lint diagnostics. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\LintResult` | Value object for reusable lint success and diagnostic results. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\LinterInterface` | Shared contract for content-based linters that can be reused by packages, editors, and debug tools. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\PhpLinter` | Reusable string-based PHP syntax linter backed by `php -l` through a temporary file. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\TwigLinter` | Reusable string-based Twig syntax linter using Twig's parser. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\YamlLinter` | Reusable string-based YAML syntax linter using Symfony YAML. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Package\PackageCandidate` | Value object for a discovered manifest-backed package candidate. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Package/PackageDiscoveryTest.php` |
| N/A | `App\Core\Package\PackageDiscovery` | Discovers application, theme, module, and cached import manifests from standard package locations. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Package/PackageDiscoveryTest.php` |
| N/A | `App\Core\Package\PackageInspection` | Value object describing package inventory and detected feature surfaces such as templates, assets, PHP, Twig, JSON, YAML, CSS, and JavaScript files. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Package/PackageValidatorTest.php` |
| N/A | `App\Core\Package\PackageSource` | Defines a package discovery source and its optional manifest specification. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Package/PackageDiscoveryTest.php` |
| N/A | `App\Core\Package\PackageSpec` | Domain-neutral package filesystem and optional preflight linting specification. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Package/PackageValidatorTest.php` |
| N/A | `App\Core\Package\PackageValidator` | Validates discovered package candidates for required files, directories, feature inventory, and optional syntax checks before dry-run planning. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Package/PackageValidatorTest.php` |
| N/A | `App\Core\Workflow\OperationIssue` | Value object for structured recoverable-operation issues. | `dev/draft/0.1.x-ErrorHandlingValidation.md` | `tests/Core/Workflow/OperationIssueTest.php` |
| N/A | `App\Core\Workflow\OperationResult` | Value object for recoverable workflow results with success, invalid, review, blocked, and failed states. | `dev/draft/0.1.x-ErrorHandlingValidation.md` | `tests/Core/Workflow/OperationResultTest.php` |
| N/A | `App\Core\Workflow\OperationStatus` | Enum for shared recoverable workflow result states. | `dev/draft/0.1.x-ErrorHandlingValidation.md` | `tests/Core/Workflow/OperationResultTest.php` |


## 2. Controllers

| Name | Class | Description | Docs | Test-Class |
|------|-------|-------------|------| ---------- |
| Stimulus `chart` | `assets/controllers/chart_controller.js` | Lazily renders ApexCharts instances from Stimulus values and destroys them on disconnect. | N/A | N/A |
| Stimulus `code-editor` | `assets/controllers/code_editor_controller.js` | Lazily mounts CodeMirror editors with CSS, HTML, JavaScript, JSX, JSON, Markdown, PHP, TypeScript, and TSX language support. | N/A | N/A |

## 3. Console Commands

| Command | Class | Description | Docs | Test-Class |
|---------|-------|-------------|------| ---------- |
| `bin/init` | `bin/init` | Initializes repository dependencies and assets for automated workflows without requiring a Symfony bootstrap before Composer is installed. | `dev/draft/0.1.x-SetupTestAutomation.md` | `tests/Operations/InitScriptTest.php` |
| `bin/setup` | `bin/setup` | Placeholder first-run setup entry point with deferred phases for repository initialization, configuration, persistence, and administrator setup. | `dev/draft/0.1.x-SetupTestAutomation.md` | `tests/Operations/SetupScriptTest.php` |

## 4. Components & Extensions

| Identifier | Class/Template | Purpose | Docs | Test-Class |
|------------|----------------|---------| ---- | ---------- |

## 6. Modules

| Module | Manifest Path | Services | Routes | Assets | Description | Docs | Test-Class |
|--------|---------------|----------|--------|--------| ----------- | ---- | ---------- |

## Maintenance Tips
- Whenever adding a new service/controller/command, update this map.
- Reference canonical developer or user docs when available instead of transient notes.
- Include test class references to ease traceability (`tests/...`).
- Mark deprecated entries clearly when refactoring.
