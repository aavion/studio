# Auto-ban branch plan

> **Status**: Draft  
> **Updated**: 2026-06-18  
> **Owner**: Core  
> **Purpose:** Define the `feat-security-auto-ban` implementation plan.  

## Goal

Add score-based temporary bans for sustained suspicious behavior across browser, API, auth, probe, and error-response surfaces, while preserving trusted-user recovery access and keeping ban decisions explainable through retained Security signals.

Back to [security hardening implementation plan](../0.2.x-SecurityHardeningPlan.md).

## Git handling

Codex may create local commits for this branch when each commit has a clear thematic scope. Pushes require explicit user instruction.

## Dependencies

- `feat-security-abuse-foundation`.
- `feat-security-rate-enforcement`.
- [Security policy defaults](policy-defaults.md).
- Existing Security signal storage, Admin log browsing, Admin ACL, user-role/access-level, visitor identity, client-IP identity, Config default provider, error rendering, and rate-limit foundations.

## Legacy inspiration

The old Grav plugin `sec-lookup` at `/Volumes/Projekte/temp/sec-lookup` may be reviewed for temporary-block workflows, ban-review ergonomics, and false-positive lessons. Current score-based Security signals, cache-flock TTL enforcement, trusted-user recovery protection, and Admin review requirements have priority. Do not copy legacy logic, thresholds, persistence, or framework-specific shortcuts directly.

## Implementation sequence

1. Add an `AutoBanScoreCatalogue` or similarly owned Security catalogue that assigns default score weights only to suspicious Security signal reasons.
2. Extend passive signal recording so relevant `400`, `403`, `404`, and `429` responses emit low-weight error-hit Security signals, with probe, obvious malformed/attack-pattern payloads, auth/rate, copied-session or copied-visitor-cookie risk, invalid API/CORS probing, setup-apply abuse, upload/archive validation abuse, diagnostic/export probing, and other explicit high-confidence abuse signals carrying stronger reason-specific weights. Ordinary login-required `401` responses are the explicit non-suspicious auth boundary and do not feed auto-ban scoring by status alone.
3. Treat suspicious probe responses and their generic `400` status as one connected risk action. The probe signal is the high-confidence source; the response-status signal may add context but must not double-count the same request as two independent actions.
4. Add an auto-ban policy service that aggregates retained, non-reset Security signals over a one-hour scoring window by Visitor ID first and by stable client IP bucket/HMAC as a secondary source subject. Score aggregation runs only from the qualifying signal write path, reusing the active database connection after signal persistence; ordinary requests that do not create a scoreable signal must not perform database score lookups.
5. Ensure scoreable request signals are persisted for every evaluated source subject, normally Visitor ID and IP bucket, with shared request/correlation context so Visitor and IP scoring can use indexed `subject_type`/`subject_identifier` reads instead of portable-unsafe JSON filtering.
6. Add bounded Owner-gated Config/Settings defaults through the existing settings registry/default provider for auto-ban enablement, trusted-user minimum access level, score threshold, Owner alert delivery for newly decided bans, and any required bounded policy constants so missing databases use seeded defaults and do not cause Doctrine/DBAL throws during setup or degraded states.
7. Add cache-flock-backed active ban state with TTL plus a cache-backed active-ban index for Admin list rendering. The ban store and index must serialize index mutations, fail open when cache/lock storage is unavailable, and must never create an invisible permanent block.
8. Emit a persistent `security_signal_event` record when a ban is triggered, including whether the effective subject was `visitor` or `ip`, the TTL/escalation context, score summary, and safe references needed for Admin review without exposing raw IPs, raw visitor-cookie tokens, headers, secrets, or raw credentials.
9. Emit a Security signal when an Owner manually resets a ban. Reset success must require active cache-state release and observable reset-signal persistence. Release the active cache state first, then record the reset cutoff; if the reset signal cannot be persisted, restore the active state best-effort and report reset failure instead of leaving a cutoff for a ban that was not successfully released. Reset signals invalidate earlier retained signals for that same subject and subject type for future score and escalation calculations, so the visitor/IP starts at zero after reset.
10. Add enforcement early enough to run before controller/error-page rendering and before rate-limit buckets are consumed, but late enough that authenticated trusted users and trusted-user-owned API keys have been resolved and can bypass active Visitor/IP bans. Active temporary bans return the shared forced bare `403 Forbidden` response with `Retry-After`, generic message, and safe Request ID only.
11. Add Owner-gated Security settings UI fields for auto-ban enablement, trusted-user minimum access level, score threshold, and newly decided ban alerts.
12. Add the active-ban Admin list with subject type, safe subject label, created timestamp, TTL expiry, and detail link. The list and detail views use the existing non-configurable `admin.settings.security` ACL gate instead of a separate auto-ban gate.
13. Add the ban detail page with retained Security signals explaining the decision and an Owner-gated manual reset button. Detail rows are newest-first and may include retained pre-reset history for review context, but historical rows must never displace newer post-reset/current evidence from the bounded view.
14. Register Admin API endpoints for listing active bans, reading one ban with retained signal context, and resetting one active ban under `/api/v1/admin/security/auto-bans`, using an explicit Owner minimum access level plus the same `admin.settings.security` ACL gate as the browser UI.

## Public interfaces and data decisions

- Auto-ban is enabled for completed installations by setup seeding the bounded Security setting to `true`. The runtime/default-provider fallback for unreadable or unavailable config storage is `false`, so active-ban evaluation and enforcement fail open during setup or database/config outages.
- Newly decided ban owner alerts are enabled by default through a bounded Security setting. Alerts use hidden warning delivery and link directly to the active-ban list so Owners can review current state before drilling into details.
- Primary source scoring is by Visitor ID. Stable client IP evidence is evaluated separately to reduce header/cookie mutation bypasses, but IP-only thresholds use a fixed multiplier above the Visitor threshold so legitimate visitors behind NAT or untrusted proxies are less likely to be blocked. User accounts and API keys are context for trusted-user bypass decisions, not auto-ban subjects.
- The initial scoring window is one hour.
- First score defaults use a Visitor threshold of `100`, an IP threshold multiplier of `2` for an effective IP threshold of `200`, and a minimum of two qualifying signals before any ban can be created.
- Initial signal weights are: error-hit `7`, suspicious probe path `100`, obvious malformed/attack-pattern payload `100`, copied session or copied visitor-cookie `100`, and failed authentication `10`. That means roughly 15 error hits, one high-confidence probe or payload signature plus another qualifying signal, one session-copy signal plus another qualifying signal, or 10 failed auth attempts can reach the Visitor threshold inside the one-hour window.
- Triggered ban TTLs escalate globally as `1h`, `3h`, `24h`, and `7d`.
- Escalation is derived from retained prior ban-triggered `security_signal_event` records for the same subject type. Visitor and IP escalation counts are separate because ban-trigger signals record whether the ban was for Visitor ID or IP.
- When one incoming Security signal makes both Visitor and IP scores eligible, create at most one new active ban for that signal and prefer the Visitor ban. Create an IP ban only when the IP score crosses the laxer threshold and no Visitor ban is created for the same evaluation. Trigger signals and Owner alerts are emitted only when the active ban state is newly created, not when a concurrent evaluator observes an already active ban. This preserves the IP defense against cookie/header mutation without unnecessarily broadening NAT impact.
- Scoreable request signals should be recorded per evaluated source subject, not only as a primary subject with the IP bucket hidden in JSON context. The same request may therefore create paired Visitor/IP signal rows with a shared request ID or correlation context, while Admin detail views deduplicate them for human review.
- Score aggregation is write-triggered, not request-triggered. After a scoreable `security_signal_event` insert succeeds, the same DB connection may query retained rows for the affected Visitor/IP subjects using the existing `subject_type`, `subject_identifier`, and `occurred_at` index, apply the latest reset cutoff and one-hour window, and decide whether to write a ban-trigger signal plus cache-flock state. Requests with no new scoreable signal only perform the cheap active-ban cache check.
- Auto-ban detail may show coarse GeoIP audit context for the banned subject by reading the latest retained ban-trigger signal's Request ID and joining that Request ID to the access-log projection. Security signals must not duplicate raw IP or per-signal GeoIP values; the detail view should expose only the trigger request's country and continent, with `n/a` fallback when access-log context is missing or expired.
- Security-signal retention resets escalation naturally. Once prior ban-trigger signals expire or are reset, later bans start from the lower escalation tier again.
- Manual reset takes effect by deleting/clearing active cache-flock ban state before recording the reset signal. If the reset signal cannot be persisted after release, the active state is restored best-effort and the reset is reported as failed. Score and escalation queries ignore earlier signals at or before the latest reset signal for the same subject type/key.
- Threshold changes apply immediately for new decisions only. Existing active bans are not lifted automatically. If a subject is now above a lowered threshold but is not currently banned, the next qualifying Security signal triggers evaluation and may create the ban. This policy is intentional and should be preserved in reviews.
- Score thresholds and suspicious-action weights must be floored so at least one action always gets through and a ban cannot be created before the second qualifying signal for that subject type.
- The first implementation uses stable code defaults in a score catalogue, with settings only for enablement, trusted-user minimum access level, and score threshold. Per-signal weight tuning may become configurable later only at the catalogue boundary with tests and policy updates.
- The score is global per subject type/key, not separated into multiple buckets. Signal reasons decide weight; bucket family is diagnostic context only.
- Evaluated Security signals are limited to source-risk signals. Routine access logs, ordinary successful requests, expected validation failures, login-required `401` responses, and shared ignorable static/tooling/well-known paths do not contribute by status alone. Normal application `404`, `403`, and `429` responses can still be weak signals because repeated misses, denials, or limiter collisions in a short window are source risk.
- Honeypot/probe and obvious malformed/attack-pattern payload signals may carry high scores because they represent high-confidence scanner behavior. Payload scanning is limited to public/untrusted request surfaces and must skip Admin, Editor, Setup, and trusted-user contexts so legitimate code/template/content fields such as schema custom Twig are not recorded as probes. Payload signal context must store only pattern classes and safe parameter names/sources, never submitted raw values. Ordinary error-page and rate-limit hits must stay low enough that a single legitimate mistake is harmless while repeated hits can still cross the threshold.
- Trusted registered users are never auto-banned. The trusted-user minimum access level is required, defaults to `6`/`MANAGER`, and cannot be empty. Because Owners have level `9`, the required setting also protects Owners from self-lockout through auto-ban. Valid API keys owned by trusted users inherit this bypass because the trusted user context has been resolved before active ban enforcement.
- The recovery login render path `/user/login?bypass=1`, resolved through the shared `RequestPathResolver`, stays reachable despite active Visitor/IP bans. The normal login submission rendered from that recovery page must also be able to reach authentication so a trusted Owner context can be established, but only when it carries the explicit recovery marker rendered with that form. Ordinary `POST /user/login` submissions remain subject to active Visitor/IP bans. The bypass route keeps its separate strict rate limiting and does not bypass CSRF, credential validation, login-failure accounting, audit logging, or post-login policy re-evaluation.
- Ban decisions follow the Security policy enforcement order so trusted-user context, trusted-user API-key context, active Admin/Owner session context, and recovery-login rendering are resolved before Visitor/IP bans can deny access, while active bans still run before error pages or rate-limit responses can be produced.
- `/api/live/**` stays outside ordinary rate-limit rejection, but it is not an active auto-ban bypass. Once a Visitor/IP source is actively banned, live JSON endpoints must receive the same bare `403` enforcement unless the trusted-user or recovery-login policy applies.
- Config keys must be registered through the settings/default provider and setup seeder so setup, missing database, or unavailable database states read safe defaults without touching Doctrine/DBAL. The auto-ban enabled runtime fallback is disabled, while setup writes the completed-installation value as enabled. When the database is unavailable, signal persistence, score evaluation, Admin list/reset, and enforcement degrade fail-open.
- Active temporary ban responses use the shared `HttpErrorRenderer` forced bare response path: `403 Forbidden`, `Retry-After` when TTL is known, `Cache-Control: no-store`, safe Request ID/reference, and a generic message. They must not expose score values, rule names, subject keys, Visitor IDs, IP buckets, raw IP data, paths, headers, signal internals, or ban history, and must not record additional Security signals for the auto-ban `403`. They still remain normal access-log entries so Owners can correlate Request ID, Visitor ID, status, and retained signal context during manual audits.
- Cache-flock state is the active enforcement state holder. Explainability and escalation come from retained `security_signal_event` records, including ban-trigger and reset records, not from a separate durable ban table.
- The active-ban list is backed by the active ban store's cache index, while detail/reason evidence is backed by retained Security signals. If the cache index cannot be updated atomically with newly created active state, the new active state must be rolled back so enforcement does not create an unreviewable ban. If the cache index is unavailable or inconsistent, enforcement must fail open and the Admin UI should show a safe degraded-state diagnostic rather than inferring active bans from stale historical signals alone.
- Ban-state keys come only from the shared subject/client-identity resolver and are limited to source subjects such as Visitor ID and stable IP bucket/HMAC. Raw IP strings, raw forwarding headers, raw API keys, credentials, usernames, emails, session IDs, visitor-cookie material, user IDs, and API-key identifiers must never become active auto-ban keys.
- IP-derived evaluation and Admin review remain within existing IP-retention ceilings. IP ban TTLs must not exceed the seven-day maximum TTL and must never extend queryable IP-derived evidence beyond retention.
- First implementation should use explicit subscriber priorities relative to existing security hooks: suspicious probe handling remains earliest, trusted-user/API-key context must be available before ordinary active-ban enforcement, and ordinary active-ban enforcement must run before `RateLimitRequestSubscriber::onKernelRequestOrdinary()` can consume buckets or return `429`. If a single priority cannot satisfy both browser-session and API-key context, split browser and API ban checks by request family while preserving this ordering.

## Edge cases

- Expired cache-flock bans must stop blocking even if cleanup is delayed.
- Missing or stale active-ban index entries must not create enforcement decisions by themselves; the authoritative active block is the per-subject cache-flock TTL state.
- Trusted users at or above the configured minimum access level must not be banned by Visitor ID or IP source scoring; valid API keys owned by trusted users must bypass existing Visitor/IP bans under the trusted-user rule.
- Owner sessions and Owner recovery must remain available even when the current Visitor ID or IP bucket is actively banned.
- Setup/install states may have no database and no Owner. Auto-ban must no-op/fail open in those states except for DB-free probe/error rendering already handled by earlier branches.
- Shared IPs can be blocked only after the laxer IP threshold is crossed and should not prevent trusted users from using the recovery/login path.
- Concurrent signal recording, score evaluation, ban creation, TTL expiry, and manual reset must be idempotent. A reset racing with ban creation must not leave a hidden active ban.
- Storage degradation in Security signal storage, cache, lock/flock, Config, or clock services must not hard-block the request or hide Owner recovery.
- If a scoreable signal is recorded but the subsequent score query or cache-flock ban creation fails, the request remains allowed or proceeds with the response already selected by the owning workflow. The persisted signal can still support later review, but auto-ban does not retry synchronously on unrelated requests.
- Status-code signals must be low-weight enough that ordinary content misses, permission denials, expected form validation, and occasional strict/panic `429` recovery paths do not create false positives, while repeated `400`/`403`/`404`/`429` hits in the scoring window still become source-risk evidence.
- Probe handling must not double-count one request as both an independent probe action and an independent `400` error action.
- Lowering the threshold must not retroactively unblock active bans; raising it must not retroactively erase retained evidence or escalation signals.

## Tests and validation

- Test score aggregation over the one-hour window by Visitor ID and by IP subject.
- Test score floors so the first qualifying signal cannot trigger a ban and the second qualifying signal can trigger only when the configured threshold/weights justify it.
- Test suspicious probe plus `400` response correlation without double-counting one request.
- Test obvious malformed/attack-pattern payload signals for GET/POST parameters, including redaction of raw submitted values, skip behavior for auto-ban enforcement requests, and Admin/Editor/Setup/trusted-context exclusions for legitimate code-bearing forms.
- Test paired Visitor/IP source-signal persistence and Admin detail de-duplication so IP scoring does not depend on JSON-context filtering.
- Test that score aggregation runs only after scoreable signal writes and that ordinary non-signal requests perform no database score lookup beyond the active cache-ban check.
- Test that one evaluation creates at most one active ban and prefers Visitor over IP when both thresholds are crossed.
- Test `400`, `403`, `404`, and `429` Security-signal creation as low-weight source-risk signals, with login-required `401` excluded from auto-ban scoring by status alone.
- Test threshold changes: existing bans stay active, and newly over-threshold subjects are banned only after the next qualifying signal.
- Test TTL escalation `1h`, `3h`, `24h`, `7d` from retained ban-trigger Security signals and separate Visitor/IP escalation counts.
- Test retention expiry and manual reset signals invalidate earlier score/escalation evidence.
- Test active, expired, and manually reset cache-flock ban states.
- Test active-ban cache index list rendering, stale-index cleanup/degraded diagnostics, and that stale index entries do not block without active per-subject TTL state.
- Test fail-open behavior when database, Config, cache, lock/flock, or signal storage is unavailable.
- Test trusted registered users at and above the configured minimum access level are never auto-banned, with the default `MANAGER` level and Owner lockout protection covered.
- Test valid trusted-user-owned API keys bypass active Visitor/IP bans after API-key authentication resolves the trusted user context, while non-trusted or invalid API-key requests remain subject to Visitor/IP source enforcement.
- Test subscriber ordering against existing probe, API authentication, browser session, ordinary rate-limit, and error-rendering hooks.
- Test recovery-login bypass render despite active Visitor/IP bans, dedicated recovery-login bucket behavior, CSRF/credential/failure accounting, audit logging, and post-login re-evaluation.
- Test `/api/live/**` remains outside ordinary rate-limit `429` handling but does not bypass an already active auto-ban.
- Test bare browser `403` response shape, `Retry-After`, request ID, `no-store`, and redaction.
- Test that bare auto-ban `403` responses remain access-log entries even though they do not emit new Security signals.
- Test Admin active-ban list, detail filtering, API list/detail/reset endpoints, and manual reset permissions/audit/signal creation.
- Test auto-ban detail GeoIP enrichment from the latest ban-trigger Request ID without copying GeoIP values into every Security signal.
- Test that delegated non-Owner admins cannot access active-ban browser or API surfaces through the `admin.settings.security` ACL gate.
- Test settings descriptors, default provider values, validation bounds, translations, and missing-database defaults.
- Test migration/schema only if this branch changes existing Security signal fields; the preferred implementation should avoid new ban tables.
- Test `php bin/console lint:container` after service/config changes.

## Documentation and tracking

- Update Security policy defaults with final score weights, threshold default, multiplier, TTL escalation, trusted-user default, and response semantics.
- Update Security settings documentation/manual notes once the UI lands.
- Update Admin/security diagnostics notes for active-ban list, detail review, owner alerts, API endpoints, and manual reset semantics.
- Update class map for the score catalogue, policy service, cache-flock store, enforcement subscriber, settings descriptors, Admin routes/controllers/API handlers, and tests.
- Record threshold and false-positive assumptions in the worklog.
- Complete the Security PR-readiness checklist from the master hardening plan before opening the PR.

## Non-goals

- No permanent invisible deny list.
- No GeoIP/country blocking.
- No machine-learning risk scoring.
- No durable auto-ban table in the first implementation unless signal-store evidence proves cache-flock state is insufficient.
- No per-signal Admin weight editor in this branch.

## Acceptance criteria

- Clear suspicious behavior is scored across relevant Security signals and temporarily blocked only after repeated evidence.
- Operators can understand active bans through filtered Security signals and can reset them immediately.
- Trusted users and Owner recovery remain available.
- Missing database or ban-store degradation fails open instead of creating lockout risk.
