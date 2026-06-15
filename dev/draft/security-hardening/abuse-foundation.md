# Abuse foundation branch plan

> **Status**: Draft  
> **Updated**: 2026-06-15  
> **Owner**: Core  
> **Purpose:** Define the `feat-security-abuse-foundation` implementation plan.  

## Goal

Introduce Studio-owned request classification, subject resolution, action costs, and passive suspicious-signal recording before any broad enforcement or auto-ban behavior is enabled.

Back to [security hardening implementation plan](../0.2.x-SecurityHardeningPlan.md).

## Git handling

Codex may create local commits for this branch when each commit has a clear thematic scope. Pushes require explicit user instruction.

## Dependencies

- [Security policy defaults](policy-defaults.md).
- Existing visitor identity, access logging, audit logging, API-key authentication, Scheduler API authentication, and `/api/live/**` route boundaries.
- Symfony Request data and Turbo/browser prefetch headers.

## Legacy inspiration

The old Grav plugin `sec-lookup` at `/Volumes/Projekte/temp/sec-lookup` may be reviewed for suspicious-request categories, passive-signal examples, and diagnostics language. Current subject-resolution, privacy, database-portability, and no-enforcement decisions in this branch have priority. Do not copy legacy logic or framework-specific request handling directly.

## Implementation sequence

1. Add an abuse namespace with value objects for subject, request family, request intent, action cost, and passive signal.
2. Add subject resolution for IP bucket, visitor ID, authenticated user UID, API key UID/prefix, and safe combined subject keys through one reviewed client-identity resolver.
3. Add request-intent classification for browser navigation, Turbo/browser prefetch, form submit, API read, API write, scheduler trigger, captcha refresh, captcha failure, login, registration, password reset, contact, import, and suspicious probe.
4. Add a central action-cost catalogue with website and API families. Costs are symbolic defaults, not limiter calls yet.
5. Add database-backed passive suspicious-signal recording with TTL-ready metadata, cleanup support, and redacted message/audit reporting.
6. Add explicit `/api/live/**` classification: no ordinary enforcement, but passive signal recording can happen for clear abuse patterns.

## Public interfaces and data decisions

- Controllers and future packages call a Studio-owned abuse facade instead of Symfony RateLimiter directly.
- Client identity must respect Symfony trusted-proxy configuration and must not trust raw forwarding headers outside that configuration.
- Prefetch detection uses `X-Sec-Purpose: prefetch` and `Sec-Purpose: prefetch`; spoofable hints only lower confidence for classification, never bypass checks.
- Signals store only normalized subject keys, intent, reason code, count/weight, timestamps, and safe request metadata.
- Probe-path detection is configurable and ships with extensive high-signal defaults for `.env`, VCS metadata, backup/database dumps, common foreign admin panels, upload shells, and known scanner paths.
- First implementation uses a portable database table for short-lived passive signals. Suggested fields are normalized subject type/key, request family, intent, reason code, confidence, weight/count, first-seen timestamp, last-seen timestamp, expiry timestamp, safe context hash, and optional audit reference.
- Passive-signal rows are observational only in this branch. The rate and auto-ban branches decide how to consume them for enforcement.
- Keep passive signals separate from raw file logs. If a broader database-backed security event projection is introduced later, this branch's signal store should either feed it through a documented boundary or remain the focused enforcement-oriented read model.
- IP subjects and stable IP-derived hashes must expire within 30 days. Longer-lived passive signals must use visitor ID, authenticated user ID, API key fingerprint, or aggregate keys without retaining the IP-derived subject.
- TTL and expiry use an injectable clock/time boundary for deterministic tests.

## Edge cases

- Missing visitor cookie uses the existing fallback visitor identity.
- Invalid Bearer API keys should still classify as API activity without trusting the key as an authenticated subject.
- Authenticated Owner requests still classify normally; Owner lockout protection is enforced in later branches.
- High-signal probe paths are suspicious even when the route does not exist or is only a honeypot; later enforcement should return a generic `400` without revealing route existence.
- Prefetch for state-changing methods is suspicious; normal GET prefetch remains low-confidence.
- Expired passive signals must not affect later enforcement once rate/ban branches start consuming the store.
- Passive-signal storage failure records a safe diagnostic and must not change request outcome in this foundation branch.
- Cleanup must remove or anonymize expired IP-derived signal keys before any Admin export, support bundle, or statistics projection can expose them.

## Tests and validation

- Test subject resolution for anonymous, visitor-cookie, authenticated user, valid API key, invalid API key, and scheduler trigger.
- Test intent classification for browser, prefetch, API read/write, `/api/live/**`, login, registration, password reset, and suspicious probes.
- Test configurable probe-path defaults and high-signal probe classification.
- Test redaction in passive signal messages.
- Test passive-signal persistence, aggregation by normalized subject/intent/reason, expiry filtering, and cleanup command/task behavior.
- Test IP-derived signal retention stays below 30 days and that longer-lived visitor-based signals do not keep recoverable IP material.
- Test trusted-proxy/client-identity behavior and storage-failure degradation.
- Test no limiter or ban enforcement occurs in this branch.

## Documentation and tracking

- Update Security draft with facade and classification names if they become stable public extension points.
- Update class map for the facade and value objects only if they are contributor-facing services.
- Update class map for the passive-signal entity/repository/cleanup command if they are added.
- Record default cost catalogue decisions in the worklog.
- Update Security policy defaults if implementation evidence changes signal retention, subject composition, or suspicious-intent weighting.
- Record whether the branch keeps only the passive-signal store or also introduces/reuses a broader security event projection.
- Complete the Security PR-readiness checklist from the master hardening plan before opening the PR.

## Non-goals

- No `429` responses.
- No rate limiter bucket consumption.
- No auto-ban or captcha provider logic.

## Acceptance criteria

- Later branches can enforce limits and bans through one facade.
- Passive signal output is useful for review without affecting user traffic.
