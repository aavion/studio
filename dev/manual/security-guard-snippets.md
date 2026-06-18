# Security guard snippets

> **Status**: Draft  
> **Updated**: 2026-06-18  
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

## Auto-ban guardrails

Auto-ban enforcement is temporary, source-subject based, and fail-open:

- Score aggregation runs only after a scoreable `security_signal_event` write. Ordinary requests perform only the active cache-state check.
- Active ban state lives in cache-backed TTL entries with a cache-backed Admin index. Retained Security signals explain trigger and reset history; there is no durable ban table.
- Visitor ID is the primary source subject. IP bucket/HMAC is evaluated separately with a laxer threshold multiplier.
- Trusted registered users at or above the configured trusted level, trusted-user-owned API keys, the recovery login render path, and login submissions carrying the recovery marker rendered from that recovery path must bypass active Visitor/IP bans so trusted Owners can recover.
- Owner review surfaces for active bans use the non-configurable `admin.settings.security` ACL gate. The browser list/detail views and `/api/v1/admin/security/auto-bans` endpoints must reject delegated non-Owner admins, and the API endpoint metadata must advertise the same Owner minimum access level.
- Newly decided ban alerts are configurable and enabled by default. When enabled, active Owner accounts receive a hidden warning with an action link to the active-ban list.
- Disabling auto-ban stops score evaluation and active-ban enforcement immediately. Existing TTL cache entries may remain until they expire, but they must not block requests while the feature is disabled.
- If config storage is unavailable, auto-ban enablement falls back to disabled even when cached active-ban TTL entries exist. Setup writes the completed-installation enabled value once the database is ready.
- Shared ignorable static/tooling/well-known paths such as generated assets, profiler/toolbar, favicon/touch icons, robots/sitemap, and selected `.well-known` discovery files must not create passive error-status Security signals.
- The Symfony `test` environment disables kernel-triggered auto-ban evaluation and enforcement unless a request explicitly opts in with `X-Auto-Ban-Testing: 1`, so broad controller suites that intentionally render many error responses do not poison shared test cache state.
- Active ban responses use the forced bare `403` path with `Retry-After`, `no-store`, a generic message, and a safe Request ID only. Auto-ban enforcement responses must not create additional passive Security signals.
- Manual reset clears active cache state, records a reset Security signal, and returns a success/error alert for the release workflow. Reset success requires both reset-signal persistence and active cache-state release. Score and escalation queries ignore earlier evidence for the same subject after that reset.

## Web server notes

- Apache fallback redirects must preserve base paths.
- IIS `index.php` redirects must preserve virtual directory prefixes.
- Prefer server-level Apache configuration with `AllowOverride None`.
- Keep `.htaccess` as a shared-hosting fallback only.

## References

- [Web server configuration](web-server-configuration.md)
- [Operation issue catalog](operation-issue-catalog.md)
- [Security and access control draft](../draft/0.2.x-SecurityAccessControl.md)
