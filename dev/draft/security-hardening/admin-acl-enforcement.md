# Admin ACL enforcement branch plan

> **Status**: Draft  
> **Updated**: 2026-06-15  
> **Owner**: Core  
> **Purpose:** Define the `feat-security-admin-acl-enforcement` implementation plan.  

## Goal

Introduce a shared Admin action authority policy that separates delegated Admin capabilities from Owner-only site-control actions across Admin UI, API handlers, live operations, scheduler/admin controls, and service-layer workflows.

Back to [security hardening implementation plan](../0.2.x-SecurityHardeningPlan.md).

This branch should make it obvious which Admin features are operational delegation and which are site-control powers. The first implementation should ship safe code-owned defaults, then leave room for a later Owner-only configuration UI where that is useful and safe.

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
3. Add an Admin action catalogue with metadata: identifier, domain, title/description translation keys, default minimum role, sensitivity, mutation/read flag, configurable flag, audit category, and optional confirmation requirement.
4. Add a shared Admin action authority policy service that evaluates actor access level, action identifier, target context, target subject, account status, and optional workflow metadata.
5. Encode the first static default matrix in code: delegated Admin read/mutate actions, Owner-only actions, and denied/unknown actions.
6. Add a narrow Owner-only configuration descriptor shape for later tuning, but do not build a broad permission UI unless this branch can keep validation, audit, docs, and rollback small enough for review.
7. Wire enforcement at service/API/live-operation boundaries for implemented high-impact Admin workflows, starting with the existing user-management policy as the model and avoiding duplicated controller-only checks.
8. Update Admin navigation/read models to hide or disable forbidden actions using the same policy, while keeping backend enforcement authoritative.
9. Add audit/message context for denied high-impact actions without leaking protected values or action internals.
10. Add extension points for future package-owned Admin actions only after core action identifiers and collision rules are stable.

## Default authority matrix

The first matrix should use conservative defaults. "View" means the actor may open a redacted screen or summary. "Mutate" means the actor may trigger the workflow after its own CSRF, confirmation, domain validation, and audit checks also pass.

| Area | Admin default | Owner default | Notes |
| --- | --- | --- | --- |
| Dashboard/system status | View | View | Admin-visible status must be redacted and avoid secrets, raw env dumps, cookies, and request payloads. |
| General site settings | View and mutate bounded site-specific values | View and mutate | Examples: site title, footer text, default language, home path, simple dashboard preferences. |
| User management | View and mutate non-owner/non-peer-admin users within existing role/group guardrails | View and mutate all users within last-Owner guardrails | Existing `AdminUserAccessPolicy` stays authoritative for peer-role, group, self-lockout, and last-Owner rules. |
| ACL groups | View and mutate groups below actor role | View and mutate all groups within system constraints | Group impact review remains mandatory for broad updates/deletes. |
| Registration/user-flow settings | View and mutate low-risk workflow settings | View and mutate | TTLs and notification addresses are Admin-mutable only if they do not affect Owner recovery, security policy, or protected secrets. |
| Mail settings | View and mutate non-secret sender settings | View and mutate, including protected transport status/config where implemented | Mail transport secrets remain protected/write-only. Production delivery guards remain enforced. |
| Security settings | View redacted status only | View and mutate | Captcha provider selection may be Admin-mutable only if it cannot disable required protection or verified recovery policy. Auto-ban disablement, privacy ceilings, recovery protections, and rate/security policy bounds are Owner-only. |
| Access/audit/security logs | View redacted summaries | View redacted summaries and broader review tools | Raw secrets, raw tokens, full request payloads, and IP-derived data beyond retention are never exposed. Full diagnostic/export actions are Owner-only. |
| Statistics and GeoIP status | View summaries | View and mutate provider/update settings | MaxMind/license material is protected/write-only. GeoIP cannot become blocking policy in this slice. |
| API settings | View status and own/user-token surfaces where already allowed | View and mutate global API settings | Enabling public API/CORS expansion, wildcard-like origins, or broad anonymous access is Owner-only. |
| Package/theme overview | View installed/available status | View and mutate | Installing, activating, deactivating, updating, purging, and running package lifecycle actions are Owner-only by default. |
| Package settings | View and mutate simple non-sensitive package settings if the package declares them Admin-safe | View and mutate all package settings within package policy | Package settings that alter routes, permissions, external credentials, data access, or runtime code are Owner-only. |
| Scheduler | View status and run safe non-destructive tasks when allowed | View and mutate | Enabling scheduler web trigger, GET-token fallback, package ActionQueue tasks, or destructive tasks is Owner-only. |
| Operations/action logs | View redacted operation summaries | View redacted summaries and emergency controls | Clearing stale locks may be Admin-safe; emergency stop/kill, retrying destructive operations, and continuation of Owner-only workflows are Owner-only. |
| Cache/asset rebuild | Mutate when non-destructive and recoverable | Mutate | Rebuilds remain audited and use the live-operation shell. |
| Backup/export/download | View redacted status | View and mutate | Backup creation may later be Admin-mutable if it produces protected storage-only artifacts; full download/export/restore stays Owner-only by default. |
| Import/apply | View previews where editor/admin permissions allow | View and mutate Owner-sensitive imports | Applying imports that change configuration, packages, ACLs, users, or system data is Owner-only. Content imports may belong to editor/content ACL policy. |
| Self-update/release | No access by default | View and mutate | Update checks that only show available versions may become Admin-viewable; apply/update/rollback remains Owner-only. |
| Diagnostics/support bundles | View redacted status | Generate/download redacted bundles | Bundle generation/download is Owner-only unless a later policy creates a strictly redacted Admin-safe bundle. |
| Setup/recovery/emergency controls | No runtime access after setup | Owner-only or CLI/manual recovery | Setup routes must not become alternate Admin entry points after setup completion. |

## Configurability policy

- The first implementation should be code-owned and test-backed. This avoids shipping a confusing half-permission UI while the Admin surface is still changing.
- A later Owner-only settings UI may relax or tighten selected Admin capabilities only through bounded descriptors. Each configurable action must define default minimum role, allowed role range, whether it may be disabled, audit behavior, affected routes/API/live operations, and safe rollback.
- Some actions are not ordinary configurable settings: last-Owner protection, Owner recovery, protected secret redaction, privacy ceilings, raw-token exposure, `APP_SECRET` emergency handling, and unknown-action deny-by-default.
- Owner configuration may delegate additional read or mutation actions to Admins, but it must not allow Admins to grant themselves Owner role, change Owner-only recovery/security boundaries, reveal secrets without a dedicated reveal flow, or bypass domain confirmations/audit.
- Configured changes to Admin action authority must be audited with actor, old/new policy summary, affected action identifiers, and redacted context.
- If configuration is missing, corrupt, or references an unknown action identifier, runtime must fall back to safe code defaults and record diagnostics.

## Public interfaces and data decisions

- Admin action identifiers are stable, English, machine-readable strings and are not localized.
- The first matrix is code-owned and test-backed. Database-configurable Admin ACLs are a later Owner-only feature only if product need appears and the bounded descriptor model is implemented.
- `Admin` is a delegated operations role. `Owner` remains the site-control role.
- Owner-only defaults include protected secrets, Security policy bounds, public API/CORS expansion, scheduler web-trigger/GET-token enablement, package install/activate/purge/update, backup restore, full-data exports/downloads, self-update/release actions, destructive data/package purge, peer Admin changes, Owner changes, and emergency global operational controls.
- Delegated Admin defaults include normal dashboards, redacted diagnostics, package/theme overviews, scheduler status, non-secret settings, user review queues, operational summaries, non-owner user management, ACL groups below the actor role level, bounded non-secret settings, and non-destructive cache/asset rebuilds where workflow policy allows them.
- Navigation visibility is not enforcement. Controllers, API handlers, live-operation starters, scheduler/admin triggers, and service-layer workflows must enforce the same policy before mutation or sensitive reveal.
- Protected values remain write-only or status-only even for Owners unless a dedicated reveal flow adds re-authentication, audit, and redaction tests.
- Package-owned Admin actions must use package-scoped identifiers and must not override system action identifiers.
- Denied decisions should return structured messages compatible with browser, API JSON, and live-operation feedback.
- The action catalogue should be discoverable for Admin navigation/read models, tests, and future documentation generation.

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
- Configurable policy must not create a state where no Owner can recover or where Admins can silently promote themselves to Owner-equivalent authority.
- Downgrading an Owner session to Admin authority through configuration is invalid; role hierarchy remains the foundation.

## Tests and validation

- Test the static matrix for Admin, Owner, lower-role users, anonymous users, inactive/deleted users, and API-key actors.
- Test existing user/ACL management behavior remains at least as strict as `AdminUserAccessPolicy`.
- Test every row of the default authority matrix where the underlying surface exists, using at least one allowed Admin case, one denied Admin case, and one Owner case per domain.
- Test Admin can view allowed summaries but cannot mutate Owner-only package, scheduler, security, backup, update, or protected-secret surfaces.
- Test Owner can perform Owner-only actions when the workflow's own confirmation, CSRF, status, and domain validation pass.
- Test navigation/read-model visibility and backend enforcement use the same policy.
- Test Admin API handlers use the API key owner's authority.
- Test live-operation queueing and continuation re-check authority.
- Test denied actions produce stable redacted messages and audit context.
- Test package-scoped action identifier validation rejects collisions with system actions.
- If configurability is implemented, test missing/corrupt/unknown configuration fallback, Owner-only mutation of the matrix, audit of matrix changes, and rejection of unsafe delegation.
- Run focused controller/API/live-operation tests and `lint:container` when services are added.

## Documentation and tracking

- Update the Security and Admin UI drafts with the final action identifier naming convention and first matrix.
- Update any affected feature draft when a domain action is classified as Admin-safe or Owner-only.
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
