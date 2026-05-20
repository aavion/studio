# Agents Working Directory

> **Status**: Active  
> **Updated**: 2026-05-20  
> **Owner**: Dominik Letica, OpenAI/Codex  
> **Purpose:** Provides a working directory for coding agents to cache additional information and reusable tools.  

## Index

- Info: [Environment](ENVIRONMENT.md)
- Info: [Framework Version Recap](framework-version-recap.md)
- Info: [Symfony Documentation Notes](symfony-docs-notes.md)
- Tool: [Render Symfony Output](render.php)
- Tool: [Compare Translation-Keys](compare_translations.php)

## Usage
- Additional agent notes should live directly under `.codex/`. Add markdown-files here for context-optimization.
- Reusable scripts should live directly under `.codex/`. Add helpers here to avoid re-writing shell snippets.
