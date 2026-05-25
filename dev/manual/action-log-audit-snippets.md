# Action log and audit snippets

> **Status**: Draft  
> **Updated**: 2026-05-25  
> **Owner**: Core  
> **Purpose:** Capture action-log, audit, and operational event notes before persistence and UI are implemented.  

## Overview

Action logs summarize operational workflows. Audit logs record security- and compliance-relevant facts. They may overlap, but they should not be treated as the same storage model until concrete requirements exist.

The ActionLog model is a live operation overlay first. It should be able to display entries while a setup, package, asset, import, backup, or update workflow runs. The logger does not need to understand ActionLog semantics directly; anything that should be persisted as diagnostics should be emitted as structured `Message` objects with levels such as `success`, `error`, `warning`, `info`, and `debug`.

The future logger should start with an explicit recorder/service boundary. A generic operation-message event can be reconsidered after the logger exists, but it should not be the first logging design.

Current debugging baseline: callers that want to emit a single feedback item should use `MessageReporterInterface`: create a `Message`, report it, and receive the same structured message back for UI/API output. Operation boundaries should use `WorkflowResultMessageReporterInterface` before returning a `WorkflowResult`. That bridge lives in the message layer, extracts messages from workflow results and action-log payloads, logs through `MessageReporterInterface`, and returns the same result unchanged. `OperationExecutor` uses the bridge for action results; direct package lifecycle, setup, discovery, asset rebuild dispatch, PHP-loader, and public-hook failure boundaries use the same bridge instead of being forced through an `ActionQueue`.

The default service implementation, `FileMessageLogger`, appends messages to `var/log/{APP_ENV}/operations.log`. It writes translation keys only, not localized copy, and stores one compact JSON context line after each message line. The file logger is intentionally a minimal stub for development diagnostics; the later logging feature may replace the service behind the same interface.

Log entry shape:

```text
[timestamp] [LEVEL] message.translation.key
{"kind":"message","code":"package.discovery_completed","parameters":{},"message_context":{},"result_status":"success","result_context":{},"operation_context":{}}
```

Sensitive context values such as passwords, secrets, tokens, cookies, authorization headers, HMAC values, encrypted keys, API keys, and private keys must be redacted before writing the file log.

Duplicate suppression has two narrow guards. The reporter records a given `WorkflowResult` object only once, so an inner action and an outer executor cannot write the exact same result twice. The file logger also suppresses identical message signatures for the current process lifetime while keeping different contexts distinct. Repeated messages with different operation context are preserved because they may represent separate attempts or retries.

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
- backup and restore actions;
- configuration changes.

Access logs, audit logs, security logs, and operational action logs may share message levels or rendering helpers, but they should remain separate storage and retention concerns.

## References

- [Operation issue catalog](operation-issue-catalog.md)
- [Operational admin workflows draft](../draft/0.4.x-OperationalAdminWorkflows.md)
- [Security and access control draft](../draft/0.2.x-SecurityAccessControl.md)
