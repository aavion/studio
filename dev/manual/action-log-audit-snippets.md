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

`MessageLoggerInterface` is backed by Monolog through the `studio_message` channel. It writes translation keys as the log message, keeps structured message metadata in Monolog context, maps message levels to PSR log levels, and redacts sensitive context values before logging. The channel uses a 30-day rotating file handler.

Log entry shape:

```text
[timestamp] studio_message.LEVEL: message.translation.key {"kind":"message","code":"package.discovery_completed","parameters":{},"message_context":{},"result_status":"success","result_context":{},"operation_context":{}} []
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

Access logs, audit logs, security logs, and operational action logs may share message levels or rendering helpers, but they should remain separate storage and retention concerns. The first built-in channels are `studio_message`, `studio_operation`, `studio_audit`, and `studio_access`, each configured as file-based Monolog channels with 30-day retention.

`studio_operation` stores terminal live-operation summaries. These entries keep operation id, operation name, result status, timing, step/message/issue counts, and whether a continuation is available. They intentionally omit live-operation payloads, polling tokens, and raw runner output; those stay transient transport/debug artifacts.

## References

- [Operation issue catalog](operation-issue-catalog.md)
- [Operational admin workflows draft](../draft/0.4.x-OperationalAdminWorkflows.md)
- [Security and access control draft](../draft/0.2.x-SecurityAccessControl.md)
