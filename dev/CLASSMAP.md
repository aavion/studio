# Developer Class Map

> **Status**: Active  
> **Updated**: 2026-05-20  
> **Owner**: Core  
> **Purpose:** This document tracks callable entry points (services, commands, controllers, Twig components, Stimulus controllers). Keep it up to date as new classes are added or interfaces change. This document is meant to evolve alongside the codebase—treat it as a living index for developers to quickly discover callables without grepping through the project.

## 1. Services

| Service ID | Class | Description | Docs | Test-Class |
|------------|-------|-------------|------| ---------- |


## 2. Controllers

| Name | Class | Description | Docs | Test-Class |
|------|-------|-------------|------| ---------- |
| Stimulus `chart` | `assets/controllers/chart_controller.js` | Lazily renders ApexCharts instances from Stimulus values and destroys them on disconnect. | N/A | N/A |
| Stimulus `code-editor` | `assets/controllers/code_editor_controller.js` | Lazily mounts CodeMirror editors with CSS, HTML, JavaScript, JSX, JSON, Markdown, PHP, TypeScript, and TSX language support. | N/A | N/A |

## 3. Console Commands

| Command | Class | Description | Docs | Test-Class |
|---------|-------|-------------|------| ---------- |

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
