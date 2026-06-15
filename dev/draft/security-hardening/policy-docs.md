# Security policy documentation branch plan

> **Status**: Draft  
> **Updated**: 2026-06-15  
> **Owner**: Core  
> **Purpose:** Define the documentation-only baseline for the `feat-security-policy-docs` branch.  

## Goal

Align all security planning documents before runtime implementation begins. This branch creates the durable review reference for later `feat-security-*` work and keeps the active worklog focused on Security.

Back to [security hardening implementation plan](../0.2.x-SecurityHardeningPlan.md).

## Git handling

Codex may create local commits for this branch when each commit has a clear thematic scope. Pushes require explicit user instruction.

## Dependencies

- Existing security, API, captcha, scheduler, mailer, and logging drafts.
- Existing worklog archive convention in `dev/WORKLOG_HISTORY.md`.

## Implementation sequence

1. Expand the master security hardening plan with links to every detailed branch plan.
2. Add one detail file for each `feat-security-*` branch under `dev/draft/security-hardening/`.
3. Align related drafts only where product decisions changed: `/api/live/**` rate-limit exclusion, GeoIP as observability first, IconCaptcha as a dedicated branch, scoped limiter resets, Turbo/browser prefetch classification, and database-backed auto-bans.
4. Move non-Security active branch logs from `dev/WORKLOG.md` to compact sections in `dev/WORKLOG_HISTORY.md`.
5. Keep global roadmap and global To-Do items in the active worklog.

## Public interfaces and data decisions

- No runtime interfaces, routes, entities, configuration, services, commands, migrations, or translations are added in this branch.
- Documentation establishes fixed defaults for later branches: database-backed passive-signal and auto-ban TTL records, anonymous-first enforcement, lower-confidence prefetch signals, scoped `reset()` before partial refunds, ordinary rate-limit exclusion for `/api/live/**`, IconCaptcha challenge cache/TTL behavior, account-mail transport guard expectations, and minimal remember-me token management UI.

## Edge cases

- Do not archive the active `feat-security-planning` branch log.
- Preserve deferred follow-ups from old logs in compact archive entries when they are still relevant.
- Do not rewrite unrelated historical entries already archived.

## Tests and validation

- Run `bin/lint` for changed Markdown files.
- Run `git diff --check`; Markdown metadata hardbreaks may appear in raw Git output, but project lint is authoritative for Markdown.
- Confirm `dev/WORKLOG.md` has no non-Security active branch sections.
- Confirm every branch detail file links back to the master plan and the master plan links to every detail file.

## Documentation and tracking

- Update `dev/draft/README.md` if new draft paths need discoverability.
- Update `dev/WORKLOG.md` with concise planning notes only.
- Update `dev/WORKLOG_HISTORY.md` with compact archived branch summaries.

## Non-goals

- No runtime behavior.
- No threshold tuning.
- No branch implementation beyond planning and archive cleanup.
- No new open product questions unless the answer blocks a later branch from being implemented safely.

## Acceptance criteria

- A future implementer can start any `feat-security-*` branch from its detail plan without inventing product policy.
- Remaining calibration points are explicitly framed as implementation defaults to be committed and tested in the owning branch, not as unresolved product direction.
- The active worklog is short enough to serve as review notes for Security planning.
