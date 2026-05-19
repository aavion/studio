# Agents Working Directory

> **Status**: Active  
> **Updated**: 2026-05-19  
> **Owner**: Dominik Letica, OpenAI/Codex  
> **Purpose:** Provides a working directory for coding agents to cache additional information and reusable tools.  

## Index

- Info: [Environment](ENVIRONMENT.md)
- Tool: [Render Symfony Output](render.php)
- Tool: [Compare Translation-Keys](compare_translations.php)
- Cache: [Persistent Context Cache](context.cache)

## Usage
- Additional agent notes should live directly under `.codex/`. Add markdown-files here for context-optimization.
- Reusable scripts should live directly under `.codex/`. Add helpers here to avoid re-writing shell snippets.