# Admin ACL enforcement branch plan

> **Status**: Draft  
> **Updated**: 2026-06-15  
> **Owner**: Core  
> **Purpose:** Define the `feat-security-admin-acl-enforcement` implementation plan.  

## Goal

Introduce a shared Admin action authority policy that separates delegated Admin capabilities from Owner-only site-control actions across Admin UI, API handlers, live operations, scheduler/admin controls, and service-layer workflows.

Back to [security hardening implementation plan](../0.2.x-SecurityHardeningPlan.md).

## Git handling

Codex may create local commits for this branch when each commit has a clear thematic scope. Pushes require explicit user instruction.

## Dependencies

- Existing Symfony role hierarchy and `AccessLevel` model.
- Existing `AdminUserAccessPolicy` guardrails for peer roles, group assignment, self-lockout, and last-Owner protection.
- Existing backend access guard/controller context, Admin controllers, API access guard, live-operation starter/provider boundaries, and settings/package/scheduler foundations.
- [Security policy defaults](policy-defaults.md).

## Implementation sequence

1. Inventory current Admin surfaces, including settings, users/groups, package/theme management, scheduler, operations, logs/audit, backups, diagnostics, API management, and future security settings.
2. Define stable Admin action identifiers grouped by domain, for example `system.admin.settings.security.update`, `system.admin.packages.activate`, or `system.admin.scheduler.web_trigger.update`.
3. Add a shared Admin action authority policy service that evaluates actor access level, action identifier, target context, and optional subject data.
4. Encode the first static matrix in code: delegated Admin read/mutate actions, Owner-only actions, and denied/unknown actions.
5. Wire enforcement at service/API/live-operation boundaries for implemented high-impact Admin workflows, starting with the existing user-management policy as the model and avoiding duplicated controller-only checks.
6. Update Admin navigation/read models to hide or disable forbidden actions using the same policy, while keeping backend enforcement authoritative.
7. Add audit/message context for denied high-impact actions without leaking protected values or action internals.
8. Add extension points for future package-owned Admin actions only after core action identifiers and collision rules are stable.

## Public interfaces and data decisions

- Admin action identifiers are stable, English, machine-readable strings and are not localized.
- The first matrix can be code-owned and test-backed; database-configurable Admin ACLs are a later feature only if product need appears.
- `Admin` is a delegated operations role. `Owner` remains the site-control role.
- Owner-only defaults include protected secrets, Security policy bounds, public API/CORS expansion, scheduler web-trigger/GET-token enablement, package install/activate/purge/update, backup restore, full-data exports/downloads, self-update/release actions, destructive data/package purge, peer Admin changes, Owner changes, and emergency global operational controls.
- Delegated Admin defaults include normal dashboards, redacted diagnostics, package/theme overviews, scheduler status, non-secret settings, user review queues, operational summaries, non-owner user management, ACL groups below the actor role level, bounded non-secret settings, and non-destructive cache/asset rebuilds where workflow policy allows them.
- Navigation visibility is not enforcement. Controllers, API handlers, live-operation starters, scheduler/admin triggers, and service-layer workflows must enforce the same policy before mutation or sensitive reveal.
- Protected values remain write-only or status-only even for Owners unless a dedicated reveal flow adds re-authentication, audit, and redaction tests.
- Package-owned Admin actions must use package-scoped identifiers and must not override system action identifiers.

## Edge cases

- Existing user-management guardrails must stay intact and may be adapted behind the shared policy only when behavior remains equivalent or tighter.
- Owner ordinary-rate-limit exemption does not imply Owner bypass of action authorization, confirmations, audit, CSRF, account status, API-key revocation, or protected-value redaction.
- Admins may need read access to redacted diagnostics without write access to the setting or secret that produced the diagnostic.
- A denied delegated Admin action should produce a stable forbidden response/message, not fall through to "not found" unless hiding existence is an explicit policy for that resource.
- Live operations must check authority before queueing and again before continuation descriptors start a follow-up operation.
- API handlers must enforce the matrix using the API key owner's role and account status, not the key prefix or token label.
- Scheduler run-now and web-trigger controls need separate actions because status viewing, manual run, trigger enablement, and GET-token fallback are different risk levels.
- Backup/export/download actions must distinguish redacted summaries from full-data artifacts.
- If an action identifier is unknown, deny by default and record safe diagnostics.

## Tests and validation

- Test the static matrix for Admin, Owner, lower-role users, anonymous users, inactive/deleted users, and API-key actors.
- Test existing user/ACL management behavior remains at least as strict as `AdminUserAccessPolicy`.
- Test Admin can view allowed summaries but cannot mutate Owner-only package, scheduler, security, backup, update, or protected-secret surfaces.
- Test Owner can perform Owner-only actions when the workflow's own confirmation, CSRF, status, and domain validation pass.
- Test navigation/read-model visibility and backend enforcement use the same policy.
- Test Admin API handlers use the API key owner's authority.
- Test live-operation queueing and continuation re-check authority.
- Test denied actions produce stable redacted messages and audit context.
- Test package-scoped action identifier validation rejects collisions with system actions.
- Run focused controller/API/live-operation tests and `lint:container` when services are added.

## Documentation and tracking

- Update the Security and Admin UI drafts with the final action identifier naming convention and first matrix.
- Update Security policy defaults if the Admin/Owner split changes.
- Update the class map for the authority policy, action catalogue, voters/subscribers, controllers, API handlers, and live-operation integration points.
- Record implemented action identifiers, denied-by-default behavior, and verification commands in the worklog.
- Complete the Security PR-readiness checklist from the master hardening plan before opening the PR.

## Non-goals

- No full configurable Admin permission UI.
- No package permission marketplace or manifest permission model.
- No replacement for content/editor ACL rules.
- No weakening of existing Owner recovery and last-Owner protections.

## Acceptance criteria

- Admin and Owner authority are enforced through one shared policy for implemented Admin actions.
- Existing user-management restrictions remain intact.
- High-impact Admin workflows can be reviewed without guessing whether `ROLE_ADMIN` was intended to be enough.
