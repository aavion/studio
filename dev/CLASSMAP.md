# Developer Class Map

> **Status**: Active  
> **Updated**: 2026-05-22
> **Owner**: Core  
> **Purpose:** This document tracks callable entry points (services, commands, controllers, Twig components, Stimulus controllers). Keep it up to date as new classes are added or interfaces change. This document is meant to evolve alongside the codebase—treat it as a living index for developers to quickly discover callables without grepping through the project.

## 1. Services

| Service ID | Class | Description | Docs | Test-Class |
|------------|-------|-------------|------| ---------- |
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
