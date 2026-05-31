# Security guard snippets

> **Status**: Draft  
> **Updated**: 2026-05-31  
> **Owner**: Core  
> **Purpose:** Collect implementation notes for filesystem, package, operation, and configuration guards before they become formal security documentation.  

## Overview

These notes are not a full threat model. They record the guardrails already added during Core development and review so later features do not accidentally bypass them.

## Filesystem boundaries

Use `PathGuard` for root-scoped paths. Relative paths should reject:

- empty values;
- absolute paths;
- null bytes;
- `..` traversal segments.

Filesystem write/copy actions should reject:

- symlink sources where direct source reads are not allowed;
- symlink targets;
- symlink parent directories;
- existing targets when overwrite is disabled;
- directory/file conflicts.

## Symlink policy

Current default is conservative:

- file inventories skip symlinks;
- package copy planning rejects symlink sources;
- filesystem actions block symlink target paths;
- filesystem actions block symlink parent path segments.

Future exception handling, if needed, should be opt-in and explicit. Do not silently follow symlinks in installer or updater workflows.

## Operation status policy

When actions continue after an error, the final status must preserve the highest-severity result:

```text
success < requires_review < invalid < blocked < failed
```

Do not downgrade `failed` or `blocked` queues to `requires_review`.

## Secret and config notes

- Do not store secrets in manifests.
- Do not log secrets in action logs, dry-runs, context payloads, fixtures, screenshots, or documentation examples.
- Keep local environment values in `.env.local*` or Symfony secrets.
- Prefer generated secrets during setup.
- Do not rotate `APP_SECRET` during normal maintenance. Treat a changed `APP_SECRET` as an emergency response to a confirmed or likely compromise because it invalidates secret-derived hashes and encrypted values.
- The owner recovery flow after an `APP_SECRET` change is a failsafe only. Prefer direct operator recovery through `bin/setup --reset-password` when CLI access is available.

## Web server notes

- Apache fallback redirects must preserve base paths.
- IIS `index.php` redirects must preserve virtual directory prefixes.
- Prefer server-level Apache configuration with `AllowOverride None`.
- Keep `.htaccess` as a shared-hosting fallback only.

## References

- [Web server configuration](web-server-configuration.md)
- [Operation issue catalog](operation-issue-catalog.md)
- [Security and access control draft](../draft/0.2.x-SecurityAccessControl.md)
