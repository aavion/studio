# Action log and audit snippets

> **Status**: Draft  
> **Updated**: 2026-05-27  
> **Owner**: Core  
> **Purpose:** Capture action-log, audit, and operational event notes before persistence and UI are implemented.  

## Overview

Action logs summarize operational workflows. Audit logs record security- and compliance-relevant facts. They may overlap, but they should not be treated as the same storage model until concrete requirements exist.

The ActionLog model is a live operation overlay first. It should be able to display entries while a setup, package, asset, import, backup, or update workflow runs. The logger does not need to understand ActionLog semantics directly; anything that should be persisted as diagnostics should be emitted as structured `Message` objects with levels such as `success`, `error`, `warning`, `info`, and `debug`.

The future logger should start with an explicit recorder/service boundary. A generic operation-message event can be reconsidered after the logger exists, but it should not be the first logging design.

Current logging baseline: callers that want to emit a single feedback item should use `MessageReporterInterface`: create a `Message`, report it, and receive the same structured message back for UI/API output. Operation boundaries should use `WorkflowResultMessageReporterInterface` before returning a `WorkflowResult`. That bridge lives in the message layer, extracts messages from workflow results and action-log payloads, logs through `MessageReporterInterface`, and returns the same result unchanged. `OperationExecutor` uses the bridge for action results; direct package lifecycle, setup, discovery, asset rebuild dispatch, PHP-loader, and public-hook failure boundaries use the same bridge instead of being forced through an `ActionQueue`.

`MessageLoggerInterface` is backed by Monolog through the `system_message` channel. It writes translation keys as the log message, keeps structured message metadata in Monolog context, maps message levels to PSR log levels, and redacts sensitive context values before logging. Log-write failures are swallowed so reporting an issue cannot break the original recovery path. The channel uses a 30-day rotating file handler.

Log entry shape:

```text
[timestamp] system_message.LEVEL: message.translation.key {"kind":"message","code":"package.discovery_completed","parameters":{},"message_context":{},"result_status":"success","result_context":{},"operation_context":{}} []
```

Sensitive context values such as passwords, secrets, tokens, cookies, authorization headers, HMAC values, encrypted keys, API keys, and private keys must be redacted before writing the file log.

Duplicate suppression has two narrow guards. The reporter records a given `WorkflowResult` object only once, so an inner action and an outer executor cannot write the exact same result twice. The message logger also suppresses identical message signatures for the current process lifetime while keeping different contexts distinct. Repeated messages with different operation context are preserved because they may represent separate attempts or retries.

## Action log candidates

Use action logs for:

- init and setup runs;
- package imports;
- package activation and deactivation;
- asset rebuilds;
- backups;
- restores;
- updates;
- import/export workflows.

## Suggested persisted fields

```text
id
operation_name
operation_type
actor_type
actor_identifier
status
status_counts
issues
context
started_at
finished_at
duration_ms
```

## UI notes

Action-log screens should show:

- final status;
- warning/failure counts;
- ordered entries;
- issue codes;
- context payload;
- dry-run vs executed state;
- retry or rollback availability where supported.

## Audit separation

Keep audit records for:

- authentication events;
- permission changes;
- role/ACL updates;
- destructive operations;
- package activation or removal;
- package ZIP verification or installation starts;
- admin maintenance actions such as discovery, rebuild, and cache clearing;
- Operations maintenance actions such as cleanup, stale-lock clearing, and stale-runner emergency handling;
- backup and restore actions;
- configuration changes.

Built-in settings audit entries record only the actor, route, settings section or package name, result status, and changed setting keys. Submitted values are intentionally omitted.

Audit logging can be controlled from Security settings. The production default keeps the master switch enabled and records authentication, backend maintenance, Operations maintenance, package lifecycle, settings, and unknown future audit categories. Unknown categories stay enabled by default so newly introduced audit calls do not silently disappear before administrators review them.

Access logs, audit logs, security logs, and operational action logs may share message levels or rendering helpers, but they should remain separate storage and retention concerns. The first built-in file channels are message, audit, and access, each configured as file-based Monolog channels with 30-day retention. Runtime file paths should move toward `var/log/{APP_ENV}/{message|audit|access}-{rotation_date}.log` so log files stay environment-scoped and branding-neutral.

Live-operation terminal summaries are written into the message channel with `message.operation.*` keys. These entries keep operation id, operation name, result status, timing, step/message/issue counts, and whether a continuation is available. They intentionally omit live-operation payloads, polling tokens, and raw runner output; those stay transient transport/debug artifacts. The Admin Logs view can filter them from the Messages source by operation context or message key, so a separate operations log channel is not needed for now.

## Access logs and statistics

Raw access logging and access statistics are separate product surfaces. The access log keeps operational request traces for security and diagnostics, including IP address, proxy hints, user-agent, internally generated request id, optional inbound correlation id, first-party cookie-derived visitor id, requested path, resolved route, status, duration, content metadata, and GeoIP placeholders. Existing `X-Request-ID` and `X-Correlation-ID` values are never trusted as the internal request id; short safe inbound values are stored only as `correlation_id` for operator-side log matching. Known token-bearing query values, request path segments, and referrer path segments are redacted before logs, trace data, or statistics rows are written. The raw `system_visitor` cookie token is not stored; logs/statistics use a compact 128-bit `APP_SECRET`-derived visitor id so future visitor-based rate-limit buckets stay separate from IP-based buckets. The Monolog rotating handler keeps at most 30 daily files and should remain enabled because future rate-limit and suspicious-traffic features depend on this short-lived operational trail.

The core `system_visitor` cookie is a first-party technical cookie used for visitor separation, statistics, and future security buckets. It has a 30-day lifetime and is refreshed on ordinary responses. Cookie values are signed with `APP_SECRET`; when no valid cookie is available, the current request uses an IP/user-agent fallback visitor ID so cookie-disabled clients do not create a new unique visitor for every request. Fresh responses still receive random signed visitor-cookie tokens so two same-network/same-browser users do not receive the same persistent cookie when their browsers accept cookies. A short-lived visitor identity store keeps cookie hashes and fallback hashes separate: a fresh fallback identity binds only the first issued cookie, while later fallback matches do not bind additional cookies that may belong to other same-network/same-browser clients. Core does not set or read cross-site advertising or external analytics cookies. Packages that add advertising or third-party analytics must provide their own consent-aware cookie policy and must not reuse the core technical visitor cookie for profiling. A future consent registry can let packages declare cookie purposes and required consent categories while keeping consent rendering and enforcement centralized.

Authenticated sessions are bound to the current visitor ID after login or, for already active legacy sessions, on the first authenticated request without an existing binding. If an established authenticated session appears with a different visitor ID, Studio records `auth.session_visitor_mismatch_terminated`, clears the security token, invalidates the Symfony session, and redirects to login. This catches copied session cookies while avoiding false positives for sessions that were created before the binding existed.

Access statistics write a parallel database row per request with anonymized or coarse fields only. The statistics model keeps request id, compact HMAC-derived visitor id, route/status/timing facts, browser family, device type, bot flag, referrer host, preferred language, response metadata, and normalized GeoIP fields, but does not store raw IP addresses, raw user-agents, raw visitor-cookie tokens, or inbound correlation ids. Current statistics are aggregated on demand when the Admin Statistics page is opened; scheduled caching can be added later if needed.

Statistics recording, display, and aggregation can be disabled independently from raw access logging through Statistics settings. When disabled, the database recorder skips new rows and Admin Statistics reports the disabled state; raw `system_access` logging continues for operational security. The first policy setting also respects `DNT: 1` by default for statistics recording only; when DNT is not respected, the request flag is stored and shown in aggregate output. Granular statistic events older than three months are deleted during recording. Long-term compaction beyond that cutoff is intentionally deferred until the final statistic dimensions are known; premature per-field compaction would add complexity before the reporting surface is stable.

Aggregation, recording, and snapshot-store failures should be reported through the message layer so they are visible in `system_message` without blocking the user request.

Until the mailer slice exists, account setup and recovery links are delivered through `MessageLogAccountLinkDelivery`. This intentionally writes a mailer-shaped DEBUG payload to the message log so invitation, registration, password-reset, and password-change review flows are locally testable. Delivery records include stable `mail_flow_key` and `mail_template_key` values such as `account.invitation.link`, `account.registration.link`, `account.password_reset.link`, `account.registration.approval_requested`, `account.registration.approved`, `account.registration.rejected`, `account.registration.existing_account`, `account.password.changed`, `account.password_change.disputed`, `account.password_change.reactivated`, `account.closed`, and `account.restored` so the future mailer can map them to localized templates. The payload also includes the chosen `locale`, the localized template label keys, `available_parameters`, and short template replacement `parameters` such as `email`, `username`, absolute `action_url` values produced by the shared URI generator from `site.url` or already absolute URLs, `expires_at`, and `user_uid`. Plain tokens are not logged as a separate context field.

Public flows prefer the user's stored language when available, otherwise the current request locale. Administrator-triggered flows prefer the target user's stored language when available, otherwise the configured default language. Existing-account registration notices include the username in the mail parameters while the public UI keeps the same success response as a new registration. Password-change dispute notifications include the username and user UID for administrator review after the review link temporarily locks the account; reactivation notifications tell the user to recover access through password reset after the admin assigns a random password. The stub deliberately keeps clear action URLs while no real mailer exists. Replace that delivery implementation with real mail delivery before production use, or keep it only behind an explicit development/debug gate.

## References

- [Operation issue catalog](operation-issue-catalog.md)
- [Operational admin workflows draft](../draft/0.4.x-OperationalAdminWorkflows.md)
- [Security and access control draft](../draft/0.2.x-SecurityAccessControl.md)
