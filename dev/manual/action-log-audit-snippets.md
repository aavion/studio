# Action log and audit snippets

> **Status**: Draft  
> **Updated**: 2026-05-25  
> **Owner**: Core  
> **Purpose:** Capture action-log, audit, and operational event notes before persistence and UI are implemented.  

## Overview

Action logs summarize operational workflows. Audit logs record security- and compliance-relevant facts. They may overlap, but they should not be treated as the same storage model until concrete requirements exist.

The ActionLog model is a live operation overlay first. It should be able to display entries while a setup, package, asset, import, backup, or update workflow runs. Later, the same structured entries may be handed to a logger before an operation returns, using levels such as `error`, `warning`, `info`, and `debug`.

The future logger should start with an explicit recorder/service boundary. A generic operation-message event can be reconsidered after the logger exists, but it should not be the first logging design.

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
