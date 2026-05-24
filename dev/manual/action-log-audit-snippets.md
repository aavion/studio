# Action log and audit snippets

> **Status**: Draft  
> **Updated**: 2026-05-23  
> **Owner**: Core  
> **Purpose:** Capture action-log, audit, and operational event notes before persistence and UI are implemented.  

## Overview

Action logs summarize operational workflows. Audit logs record security- and compliance-relevant facts. They may overlap, but they should not be treated as the same storage model until concrete requirements exist.

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

## References

- [Operation issue catalog](operation-issue-catalog.md)
- [Operational admin workflows draft](../draft/0.4.x-OperationalAdminWorkflows.md)
- [Security and access control draft](../draft/0.2.x-SecurityAccessControl.md)
