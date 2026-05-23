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
| N/A | `App\Core\Filesystem\FileInventory` | Value object for sorted relative file and directory inventories. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Filesystem/FileInventoryScannerTest.php` |
| N/A | `App\Core\Filesystem\FileInventoryScanner` | Reusable bounded-depth filesystem scanner for packages, imports, exports, and debug tools. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Filesystem/FileInventoryScannerTest.php`, `tests/Core/Package/PackageValidatorTest.php` |
| N/A | `App\Core\Filesystem\PathGuard` | Shared relative-path normalizer, traversal guard, root joiner, and symlink ancestor detector for filesystem operations scoped to a root. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Filesystem/PathGuardTest.php`, `tests/Core/Package/PackageValidatorTest.php` |
| N/A | `App\Core\ActionLog\ActionLog` | Immutable collection for operation step entries with warning and failure summaries. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/ActionLog/ActionLogTest.php` |
| N/A | `App\Core\ActionLog\ActionLogEntry` | Value object for a setup, dry-run, installer, backup, or update action step with timing, issues, and context. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/ActionLog/ActionLogTest.php` |
| N/A | `App\Core\ActionLog\ActionLogStatus` | Enum for action log step states. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/ActionLog/ActionLogTest.php` |
| N/A | `App\Core\Diff\DiffGeneratorInterface` | Shared interface for generators that produce structured diffs from before/after values. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Diff/DiffGeneratorTest.php` |
| N/A | `App\Core\Diff\KeyValueDiffGenerator` | Structured diff generator for manifest, config, metadata, JSON-object, and database row-like key-value data. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Diff/DiffGeneratorTest.php`, `tests/Core/DryRun/DryRunPlanTest.php` |
| N/A | `App\Core\Diff\StructuredDiff` | Value object for generated diff payloads with typed changes and renderer-neutral metadata. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Diff/DiffGeneratorTest.php` |
| N/A | `App\Core\Diff\StructuredDiffChange` | Value object for one added, removed, or changed diff path. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Diff/DiffGeneratorTest.php` |
| N/A | `App\Core\Diff\StructuredDiffChangeType` | Enum for added, removed, and changed diff entries. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Diff/DiffGeneratorTest.php` |
| N/A | `App\Core\Diff\StructuredDiffType` | Enum for supported structured diff payload categories. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Diff/DiffGeneratorTest.php` |
| N/A | `App\Core\Diff\TextDiffGenerator` | Structured diff generator for text before/after data. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Diff/DiffGeneratorTest.php`, `tests/Core/DryRun/DryRunPlanTest.php` |
| N/A | `App\Core\DryRun\DryRunAction` | Value object for one planned non-mutating operation with risk, affected paths, optional diffs, and context. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/DryRun/DryRunPlanTest.php` |
| N/A | `App\Core\DryRun\DryRunDiff` | Structured dry-run diff payload for text and key-value previews. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/DryRun/DryRunPlanTest.php` |
| N/A | `App\Core\DryRun\DryRunDiffType` | Enum for supported dry-run diff payload types. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/DryRun/DryRunPlanTest.php` |
| N/A | `App\Core\DryRun\DryRunPlan` | Immutable dry-run plan with action aggregation, affected path summary, highest risk, and ActionLog export. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/DryRun/DryRunPlanTest.php` |
| N/A | `App\Core\DryRun\DryRunRisk` | Enum for dry-run action risk levels. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/DryRun/DryRunPlanTest.php` |
| N/A | `App\Core\Integrity\Checksum` | Value object for algorithm-scoped checksum values and integrity strings. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Integrity/ChecksumCalculatorTest.php` |
| N/A | `App\Core\Integrity\ChecksumCalculator` | Reusable checksum calculator for strings, files, and deterministic file sets. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Integrity/ChecksumCalculatorTest.php` |
| N/A | `App\Core\Operation\ActionQueue` | Immutable ordered queue for operation actions with execution metadata and stop-on-failure behavior. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Operation/OperationExecutorTest.php` |
| N/A | `App\Core\Operation\OperationActionInterface` | Contract for executable operation actions that can also expose a dry-run preview. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Operation/OperationExecutorTest.php` |
| N/A | `App\Core\Operation\OperationExecution` | Value object containing an operation action log and aggregate operation result. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Operation/OperationExecutorTest.php` |
| N/A | `App\Core\Operation\OperationExecutor` | Bridges dry-run planning and action execution with ActionLog output, highest-severity result aggregation, and exception mapping. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Operation/OperationExecutorTest.php` |
| N/A | `App\Core\Operation\Filesystem\CopyFileAction` | Root-scoped operation action for copying files between source and target roots with dry-run diff previews and overwrite protection. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Operation/FilesystemOperationActionTest.php` |
| N/A | `App\Core\Operation\Filesystem\EnsureDirectoryAction` | Root-scoped operation action for creating missing directories with ActionLog context. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Operation/FilesystemOperationActionTest.php` |
| N/A | `App\Core\Operation\Filesystem\WriteFileAction` | Root-scoped operation action for writing files with parent-directory creation, dry-run diffs, and overwrite protection. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Operation/FilesystemOperationActionTest.php` |
| N/A | `App\Core\Operation\Process\RunCommandAction` | Operation action for running argument-list commands with dry-run metadata, exit-code mapping, and output excerpts. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Operation/RunCommandActionTest.php` |
| N/A | `App\Core\Lint\CssLinter` | Reusable string-based CSS syntax linter using the strict Sabberworm CSS parser. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\JavaScriptLinter` | Reusable string-based JavaScript module syntax linter using Peast. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\JsonLinter` | Reusable string-based JSON syntax linter. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\LintIssue` | Value object for reusable lint diagnostics. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\LintResult` | Value object for reusable lint success and diagnostic results. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\LinterInterface` | Shared contract for content-based linters that can be reused by packages, editors, and debug tools. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\PhpLinter` | Reusable string-based PHP syntax linter backed by `php -l` through a temporary file. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\TwigLinter` | Reusable string-based Twig syntax linter using Twig's parser. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Lint\YamlLinter` | Reusable string-based YAML syntax linter using Symfony YAML. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Lint/LinterTest.php` |
| N/A | `App\Core\Access\AccessLevel` | Shared access-level constants and validation for public, editor, manager, and admin tiers. | `dev/draft/0.2.x-SecurityAccessControl.md` | `tests/Entity/CoreDatabaseModelTest.php` |
| N/A | `App\Core\Config\ConfigValueType` | Enum for typed database-backed configuration values. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Entity/CoreDatabaseModelTest.php` |
| N/A | `App\Core\Message\Message` | Universal message value object carrying code, translation key, parameters, and context for logs, output, validation, and future localization. | `dev/draft/0.1.x-ErrorHandlingValidation.md` | `tests/Core/Message/MessageTest.php` |
| N/A | `App\Core\Message\MessageCode` | Constants for core-owned machine-readable message codes while allowing third-party modules to provide their own codes. | `dev/draft/0.1.x-ErrorHandlingValidation.md` | `tests/Core/Message/MessageCodeTest.php` |
| N/A | `App\Core\Message\MessageException` | InvalidArgumentException subtype carrying a structured message with code, translation key, parameters, and context. | `dev/draft/0.1.x-ErrorHandlingValidation.md` | `tests/Core/Message/MessageExceptionTest.php` |
| N/A | `App\Core\Message\MessageKey` | Core-owned translation-key catalogue for operation issues, logs, output, validation, and future localization. | `dev/draft/0.1.x-ErrorHandlingValidation.md` | `tests/Core/Message/MessageKeyTest.php` |
| N/A | `App\Core\Package\PackageCandidate` | Value object for a discovered manifest-backed package candidate. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Package/PackageDiscoveryTest.php` |
| N/A | `App\Core\Package\PackageDiscovery` | Discovers application, theme, module, and cached import manifests from standard package locations. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Package/PackageDiscoveryTest.php` |
| N/A | `App\Core\Package\PackageInspection` | Value object describing package inventory and detected feature surfaces such as templates, assets, PHP, Twig, JSON, YAML, CSS, and JavaScript files. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Package/PackageValidatorTest.php` |
| N/A | `App\Core\Package\PackageOperationPlanner` | Translates selected package files into deterministic ActionQueues without installing or classifying packages. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Package/PackageOperationPlannerTest.php` |
| N/A | `App\Core\Package\PackageSource` | Defines a normalized, project-root-scoped package discovery source and its optional manifest specification. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Package/PackageDiscoveryTest.php`, `tests/Core/Package/PackageSourceTest.php` |
| N/A | `App\Core\Package\PackageSpec` | Domain-neutral package filesystem and optional preflight linting specification. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Package/PackageValidatorTest.php` |
| N/A | `App\Core\Package\PackageValidator` | Validates discovered package candidates for required files, directories, feature inventory, and optional syntax checks before dry-run planning. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Core/Package/PackageValidatorTest.php` |
| N/A | `App\Core\Package\ExtensionPackageType` | Enum for managed extension package types such as theme and module. | `dev/draft/0.2.x-PluginModules.md` | `tests/Entity/CoreDatabaseModelTest.php` |
| N/A | `App\Core\Package\ExtensionPackageStatus` | Enum for managed extension package activation states. | `dev/draft/0.2.x-PluginModules.md` | `tests/Entity/CoreDatabaseModelTest.php` |
| N/A | `App\Core\Workflow\OperationIssue` | Value object for structured recoverable-operation issues. | `dev/draft/0.1.x-ErrorHandlingValidation.md` | `tests/Core/Workflow/OperationIssueTest.php` |
| N/A | `App\Core\Workflow\OperationResult` | Value object for recoverable workflow results with success, invalid, review, blocked, and failed states. | `dev/draft/0.1.x-ErrorHandlingValidation.md` | `tests/Core/Workflow/OperationResultTest.php` |
| N/A | `App\Core\Workflow\OperationStatus` | Enum for shared recoverable workflow result states. | `dev/draft/0.1.x-ErrorHandlingValidation.md` | `tests/Core/Workflow/OperationResultTest.php` |
| N/A | `App\Security\ApiKeyStatus` | Enum for API key permission status such as read-only, read-write, and revoked. | `dev/draft/0.4.x-ApiLayer.md` | `tests/Entity/CoreDatabaseModelTest.php` |
| N/A | `App\Content\ContentStatus` | Enum for content workflow states such as draft, scheduled, published, and archived. | `dev/draft/0.1.x-StaticDynamicContent.md` | `tests/Entity/ContentItemTest.php` |
| N/A | `App\Content\ContentVisibility` | Enum for public/private content visibility state. | `dev/draft/0.1.x-StaticDynamicContent.md` | `tests/Entity/ContentItemTest.php` |
| N/A | `App\Content\Routing\ContentSlug` | Value object for strict lowercase ASCII content slug validation. | `dev/draft/0.1.x-StaticDynamicContent.md` | `tests/Content/Routing/ContentSlugTest.php` |
| N/A | `App\Content\Routing\ContentRouteGuard` | Guard for reserved public route prefixes and normalized content paths. | `dev/draft/0.1.x-StaticDynamicContent.md` | `tests/Content/Routing/ContentRouteGuardTest.php` |
| N/A | `App\Content\Schema\ContentSchemaField` | Constants for reserved required base field identifiers that every content schema must define. | `dev/draft/0.3.x-SchemaContentFields.md` | `tests/Content/Schema/ContentSchemaFieldTest.php` |
| N/A | `App\Content\Schema\ContentSchemaSource` | Enum for schema sources such as preset, custom, and module. | `dev/draft/0.3.x-SchemaContentFields.md` | `tests/Entity/ContentSchemaTest.php` |
| N/A | `App\Entity\ConfigEntry` | Database-backed global configuration key/value entry with typed JSON-compatible values. | `dev/draft/0.1.x-CoreArchitecture.md` | `tests/Entity/CoreDatabaseModelTest.php` |
| N/A | `App\Entity\AclGroup` | ACL group with translatable name and 0-9 access level. | `dev/draft/0.2.x-SecurityAccessControl.md` | `tests/Entity/CoreDatabaseModelTest.php` |
| N/A | `App\Entity\UserAccount` | User account model with profile JSON and many-to-many ACL group membership. | `dev/draft/0.2.x-SecurityAccessControl.md` | `tests/Entity/CoreDatabaseModelTest.php` |
| N/A | `App\Entity\ApiKey` | API key model storing prefix, HMAC lookup hash, encrypted key payload, owner, and read/write or revoked status. | `dev/draft/0.4.x-ApiLayer.md` | `tests/Entity/CoreDatabaseModelTest.php` |
| N/A | `App\Entity\ExtensionPackage` | Theme/module package management record with manifest/install metadata and activation state. | `dev/draft/0.2.x-PluginModules.md` | `tests/Entity/CoreDatabaseModelTest.php` |
| N/A | `App\Entity\SiteMenu` | Future menu container with translatable labels and ordered menu items. | `dev/draft/0.3.x-NavigationSitemapBuilder.md` | `tests/Entity/CoreDatabaseModelTest.php` |
| N/A | `App\Entity\SiteMenuItem` | Future menu item with target metadata and view ACL override fields. | `dev/draft/0.3.x-NavigationSitemapBuilder.md` | `tests/Entity/CoreDatabaseModelTest.php` |
| N/A | `App\Entity\ContentSchema` | Database-backed content type definition with nullable active schema version for disable/staging/cleanup flows. | `dev/draft/0.3.x-SchemaContentFields.md` | `tests/Entity/ContentSchemaTest.php` |
| N/A | `App\Entity\ContentSchemaVersion` | Versioned schema definition with fieldset JSON, optional custom Twig, ACL use/edit/manage rules, and required title/subtitle validation. | `dev/draft/0.3.x-SchemaContentFields.md` | `tests/Entity/ContentSchemaTest.php` |
| N/A | `App\Entity\ContentItem` | Doctrine entity for durable content identity, routing, workflow, variants, ACL metadata, audit timestamps, and flexible metadata. | `dev/draft/0.1.x-StaticDynamicContent.md` | `tests/Entity/ContentItemTest.php` |
| N/A | `App\Entity\ContentRevision` | Versioned content revision linking content items to the exact schema version that validated its fieldset. | `dev/draft/0.1.x-StaticDynamicContent.md` | `tests/Entity/ContentItemTest.php`, `tests/Entity/ContentFieldValueTest.php` |
| N/A | `App\Entity\ContentFieldValue` | Doctrine entity for localized, variant-aware schema field values attached to content revisions. | `dev/draft/0.1.x-StaticDynamicContent.md` | `tests/Entity/ContentFieldValueTest.php` |
| N/A | `App\Repository\ContentItemRepository` | Repository entry point for content item lookups, including published slug lookup. | `dev/draft/0.1.x-StaticDynamicContent.md` | N/A |
| N/A | `App\Repository\ContentFieldValueRepository` | Repository entry point for field values in a content/version/language/variant context. | `dev/draft/0.1.x-StaticDynamicContent.md` | N/A |


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
