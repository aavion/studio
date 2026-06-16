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
- [Security policy defaults](policy-defaults.md).
- Existing form, API, scheduler, login, account-token, message, and error-rendering foundations.

## Legacy inspiration

The old Grav plugin `sec-lookup` at `/Volumes/Projekte/temp/sec-lookup` may be reviewed for rate-limit pressure patterns, bucket naming ideas, and human-recovery behavior. Current Symfony RateLimiter integration, Studio facade boundaries, `/api/live/**` exclusion, and scoped `reset()` policy have priority. Do not copy legacy logic or thresholds directly.

## Implementation sequence

1. Configure named Symfony limiters for implemented workflows: login, registration, password reset, website global, API read, API write, scheduler trigger, suspicious probes, setup apply, and any already-present contact/import/captcha-failure/high-impact admin flows.
2. Add a rate decision service that maps classified intents and subjects to one or more limiter consumes.
3. Use costed `consume(n)` calls based on the action-cost catalogue.
4. Add scoped `reset()` calls after successful password login and verified provider-backed captcha validation where the workflow explicitly allows it.
5. Add stable `429` rendering: HTML through the shared error renderer for browser workflows and JSON through API responders for versioned API/scheduler flows.
6. Explicitly exclude `/api/live/**` from ordinary rate-limit rejection while preserving passive signal recording.

## Public interfaces and data decisions

- Rate-limit policy remains application-owned; packages may request classification later but do not define raw Symfony limiter names.
- Response metadata includes retry timing where Symfony provides it, without exposing internal bucket identifiers.
- Config names use stable system/security namespaces; thresholds are defaults that can become Admin settings later.
- Registration and password-reset success do not reset global buckets by default.
- The branch must commit initial threshold defaults from the Security policy defaults as named configuration/constants with behavior tests. Later branches may tune those defaults only with matching draft/worklog notes.
- Captcha-triggered limiter resets or `429` recovery require verified provider-backed challenge success. Provider `none`, missing-provider, and disabled-provider auto-success must not reset or refill any bucket.
- Website global policy uses separate deliberate burst and sustained buckets. Turbo/browser prefetch uses a separate lower-confidence observation path so speculative `GET` requests do not exhaust user-facing navigation budgets.
- Scheduler trigger policy must allow normal once-per-minute external cron calls; task due-state logic, locks, and task policies decide whether work actually runs.
- Authenticated users receive higher ordinary navigation/API limits than anonymous visitors where a workflow does not define its own explicit bucket. Owner-owned API keys and subjects tied to an active Owner session are exempt from ordinary rate-limit rejection.
- Recovery login bypass uses its own narrow bucket and only bypasses pre-login ban/rate checks needed to render the normal login form. It must not bypass CSRF, credential checks, login-failure accounting, audit logging, or post-login policy re-evaluation.
- Workflows that do not exist in the current codebase receive catalogue entries only when doing so does not create dead services, routes, or unreachable tests.
- Limiter keys come only from the shared subject/client-identity resolver and never from raw request headers or user-submitted identifiers.
- Limiter storage degradation must be explicit and tested, including safe diagnostics and Owner recovery behavior.
- Enforcement follows the Security policy order so workflow buckets, global buckets, suspicious buckets, active bans, recovery-login rendering, and Owner/Admin protections interact predictably.
- Rate-limit responses use the documented response semantics: `429`, `Retry-After` when available, family-specific HTML/JSON bodies, redacted diagnostics, and `no-store`.
- This branch owns `no-store` behavior for rate-limit, block-adjacent recovery, browser/API/scheduler error, and sensitive retry responses it touches. It should also carry the production HTTP security-header follow-up forward to a dedicated response-hardening/frontend-delivery slice: define and test CSP, `frame-ancestors`, `Referrer-Policy`, `Permissions-Policy`, `X-Content-Type-Options`, sensitive-route `no-store`, and documented route exceptions without broadening this branch into a full frontend policy rewrite.
- Threshold/window configuration should be represented through named policy descriptors with units, defaults, min/max bounds, disabled behavior, and diagnostics labels, even if the first implementation keeps those descriptors as code constants.
- Configurable threshold windows must not exceed the retention of the evidence they evaluate. If a bucket or mixed-signal policy depends on database projections, security signals, IP buckets, or audit context with shorter retention, validation must reject or clamp longer windows and expose a clear diagnostic rather than evaluating missing historical data.
- Valid CORS preflights should be cheap and must not spend mutating API budget; invalid preflight probing may spend suspicious/API metadata budget and record passive signals.
- High-impact authenticated/admin operations should use explicit action costs or workflow buckets where the current codebase exposes them. Owner ordinary-rate-limit exemption does not remove workflow confirmation, Admin/Owner action authorization, audit, or redaction requirements.
- Setup finalization/apply attempts need a dedicated workflow bucket because this surface exists before normal Owner/Admin session protections are available.

## Edge cases

- Multiple buckets may be consumed for one request; rejection should report the most user-relevant failed policy without leaking all internal counters.
- Failed login consumes login and global website budget; successful login resets only the login-attempt bucket for that subject.
- Read-only API keys hitting write routes should still follow API write policy before or alongside authorization failure as decided by the handler order.
- CORS preflight storms should not block legitimate configured browser clients through the write limiter, but invalid origin/header/method scans should remain visible to abuse diagnostics.
- `/api/live/**` operation polling must continue to function during long admin operations.
- Export/download/log/support-bundle endpoints must use `no-store`, redaction, and permission checks even when the rate limiter allows them.
- Concurrent failures and immediate success/reset sequences must not accidentally reset unrelated global buckets or hide suspicious mixed-action behavior.
- HTML `429` pages may render a captcha recovery step only when an active provider can render and validate a real challenge. Without that provider, use ordinary retry-after behavior.

## Tests and validation

- Test each guarded workflow below and above threshold.
- Test global burst and sustained website budgets catch mixed suspicious actions without counting static assets or ordinary `/api/live/**` polling.
- Test Turbo/browser prefetch does not exhaust deliberate website buckets and still records passive signals for excessive speculative traffic.
- Test scheduler triggers allow normal minutely cron calls while still limiting obvious trigger storms.
- Test setup apply, CORS preflight, high-impact admin operation, Admin-vs-Owner authority outcomes, export/download, and upload/archive validation classification attach to the expected buckets when those workflows exist.
- Test authenticated-user higher limits and Owner ordinary-rate-limit exemptions for active sessions and Owner-owned API keys.
- Test recovery-login bypass rendering, dedicated recovery bucket exhaustion, retry-after behavior, and successful-login policy re-evaluation.
- Test policy descriptor validation for invalid, missing, overly permissive, and overly restrictive threshold/window values where configuration is introduced.
- Test that configurable limiter and mixed-signal windows are rejected or clamped when they exceed the retained evidence required by that policy.
- Test successful login resets only the login bucket.
- Test verified captcha success can reset only the configured scoped bucket, while provider `none`/missing/disabled success resets nothing.
- Test captcha-on-`429` is unavailable without an active provider and falls back to retry-after behavior.
- Test `/api/live/**` never receives ordinary rate-limit `429`.
- Test browser HTML and API JSON `429` shapes.
- Test response cache headers and redaction for browser/API/scheduler limit failures.
- Test that any `no-store` headers added in this branch are route-scoped and do not claim to complete the full production HTTP security-header policy until the dedicated response-hardening/frontend-delivery slice defines CSP and related headers.
- Test that non-existing optional workflows are not wired as dead routes/services and that later workflow branches have a clear catalogue attachment point.
- Test limiter storage degradation and concurrent consume/reset behavior for the highest-risk workflows.
- Test configured limiter service wiring with `lint:container`.

## Documentation and tracking

- Update Security draft thresholds and reset behavior.
- Update Security policy defaults if implementation evidence changes any threshold, subject, or reset policy.
- Update API/Scheduler notes for JSON `429` behavior.
- Keep the HTTP security-header production-hardening follow-up linked from this branch if the full policy is still deferred after rate enforcement.
- Update class map for facade/enforcement services.
- Record focused test commands and any threshold changes in the worklog.
- Complete the Security PR-readiness checklist from the master hardening plan before opening the PR.

## Non-goals

- No auto-ban records.
- No partial refund API.
- No IconCaptcha provider.

## Acceptance criteria

- Known workflows are rate-limited through one facade.
- Successful human outcomes can clear scoped local buckets without weakening global abuse detection.
