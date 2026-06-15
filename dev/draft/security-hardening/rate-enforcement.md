# Rate enforcement branch plan

> **Status**: Draft  
> **Updated**: 2026-06-15  
> **Owner**: Core  
> **Purpose:** Define the `feat-security-rate-enforcement` implementation plan.  

## Goal

Wire Symfony RateLimiter through the Studio abuse facade so known workflows get action-aware limits, global budgets, scoped resets, and stable HTML/JSON `429` responses.

Back to [security hardening implementation plan](../0.2.x-SecurityHardeningPlan.md).

## Git handling

Codex may create local commits for this branch when each commit has a clear thematic scope. Pushes require explicit user instruction.

## Dependencies

- `feat-security-abuse-foundation`.
- Existing form, API, scheduler, login, account-token, message, and error-rendering foundations.

## Legacy inspiration

The old Grav plugin `sec-lookup` at `/Volumes/Projekte/temp/sec-lookup` may be reviewed for rate-limit pressure patterns, bucket naming ideas, and human-recovery behavior. Current Symfony RateLimiter integration, Studio facade boundaries, `/api/live/**` exclusion, and scoped `reset()` policy have priority. Do not copy legacy logic or thresholds directly.

## Implementation sequence

1. Configure named Symfony limiters for implemented workflows: login, registration, password reset, website global, API read, API write, scheduler trigger, suspicious probes, and any already-present contact/import/captcha-failure flows.
2. Add a rate decision service that maps classified intents and subjects to one or more limiter consumes.
3. Use costed `consume(n)` calls based on the action-cost catalogue.
4. Add scoped `reset()` calls after successful password login and successful captcha validation where the workflow explicitly allows it.
5. Add stable `429` rendering: HTML through the shared error renderer for browser workflows and JSON through API responders for versioned API/scheduler flows.
6. Explicitly exclude `/api/live/**` from ordinary rate-limit rejection while preserving passive signal recording.

## Public interfaces and data decisions

- Rate-limit policy remains application-owned; packages may request classification later but do not define raw Symfony limiter names.
- Response metadata includes retry timing where Symfony provides it, without exposing internal bucket identifiers.
- Config names use stable system/security namespaces; thresholds are defaults that can become Admin settings later.
- Registration and password-reset success do not reset global buckets by default.
- The branch must commit initial threshold defaults as named configuration/constants with behavior tests. Later branches may tune those defaults only with matching draft/worklog notes.
- Workflows that do not exist in the current codebase receive catalogue entries only when doing so does not create dead services, routes, or unreachable tests.

## Edge cases

- Multiple buckets may be consumed for one request; rejection should report the most user-relevant failed policy without leaking all internal counters.
- Failed login consumes login and global website budget; successful login resets only the login-attempt bucket for that subject.
- Read-only API keys hitting write routes should still follow API write policy before or alongside authorization failure as decided by the handler order.
- `/api/live/**` operation polling must continue to function during long admin operations.

## Tests and validation

- Test each guarded workflow below and above threshold.
- Test global budget catches mixed suspicious actions.
- Test successful login resets only the login bucket.
- Test `/api/live/**` never receives ordinary rate-limit `429`.
- Test browser HTML and API JSON `429` shapes.
- Test that non-existing optional workflows are not wired as dead routes/services and that later workflow branches have a clear catalogue attachment point.
- Test configured limiter service wiring with `lint:container`.

## Documentation and tracking

- Update Security draft thresholds and reset behavior.
- Update API/Scheduler notes for JSON `429` behavior.
- Update class map for facade/enforcement services.
- Record focused test commands and any threshold changes in the worklog.

## Non-goals

- No auto-ban records.
- No partial refund API.
- No IconCaptcha provider.

## Acceptance criteria

- Known workflows are rate-limited through one facade.
- Successful human outcomes can clear scoped local buckets without weakening global abuse detection.
