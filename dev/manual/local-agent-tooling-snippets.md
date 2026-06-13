# Local agent tooling snippets

> **Status**: Draft  
> **Updated**: 2026-06-13  
> **Owner**: Core  
> **Purpose:** Track local Codex helper scripts and operational notes for agent-assisted development.  

## Overview

The `.codex/` directory contains local helper scripts and notes for agent workflows. Keep helpers dry-run by default when they remove or resolve files.

## Current helpers

| Helper | Purpose |
|--------|---------|
| `.codex/clean_ignored_artifacts.php` | Lists or removes ignored build/dependency artifacts. |
| `.codex/resolve_cloud_artifacts.php` | Inspects and optionally removes iCloud/Finder conflict artifacts. |

Project-wide developer commands that are useful for agent workflows live outside `.codex/` because they are part of the application repository:

| Command | Purpose |
|---------|---------|
| `bin/lint` | Runs the full project lint suite or focused lint checks. |
| `bin/lint --diff` | Lints supported files from the current staged and unstaged Git diff when Git and a work tree are available. |
| `bin/lint --staged` | Lints supported files staged for commit when Git and a work tree are available. |
| `php bin/console render:route /path` | Renders a route through the Symfony kernel with optional debug `--role`, `--user`, `--method`, `--host`, `--https`, and `--setup-completed=0` context. |

## Cleanup notes

Use ignored-artifact cleanup before full init/review runs when the workspace may contain stale generated files:

```bash
php .codex/clean_ignored_artifacts.php
php .codex/clean_ignored_artifacts.php --apply
bin/init
```

Do not remove non-ignored local work through cleanup helpers.

## Cloud conflict notes

iCloud/Finder conflict files should be reviewed before deletion when contents differ. Prefer dry-run output first, then explicit apply modes.

## References

- [Setup and init snippets](setup-init-snippets.md)
- `.codex/README.md`
- `tests/Operations/CodexHelperScriptsTest.php`
