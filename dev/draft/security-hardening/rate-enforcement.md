# Rate enforcement branch plan

> **Status**: Draft  
> **Updated**: 2026-06-17  
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

1. Add a small rate-limit policy catalogue that owns bucket descriptors, profile scaling, retry metadata, reset eligibility, and diagnostics labels separately from request intent classification.
2. Configure named Symfony limiters or build descriptor-derived Symfony limiter factories for implemented workflows: login, registration, password reset, website global, API read, API write, scheduler trigger, suspicious probes, setup apply, captcha failure, and any already-present import/high-impact admin flows.
3. Add a rate decision service that maps classified intents and subjects to one or more limiter consumes.
4. Use costed `consume(n)` calls based on the action-cost catalogue.
5. Add scoped `reset()` calls after successful password login and verified provider-backed captcha validation where the workflow explicitly allows it.
6. Add stable `429` rendering: HTML through the shared error renderer for browser workflows and JSON through API responders for versioned API/scheduler flows.
7. Explicitly exclude `/api/live/**` from ordinary rate-limit rejection while preserving passive signal recording.

## Public interfaces and data decisions

- Rate-limit policy remains application-owned; packages may request classification later but do not define raw Symfony limiter names.
- Response metadata includes retry timing where Symfony provides it, without exposing internal bucket identifiers.
- Config names use stable system/security namespaces; thresholds are defaults that can become Admin settings later.
- Action costs remain semantic and profile-independent. The action-cost catalogue maps request intent to bucket family and credit cost; the rate-limit policy catalogue maps bucket families to capacity, window, TTL/retry metadata, reset eligibility, and diagnostics.
- Bucket descriptors live in a small dedicated PHP catalogue class so later config-backed threshold tuning can attach at one boundary without changing classifiers, subscribers, or controllers.
- Descriptor limits are stored as credit budgets generated from user-visible action counts. For example, a three-submission registration policy with a five-credit registration cost is represented as a 15-credit bucket so Symfony `consume(n)` never exceeds the bucket capacity and accidentally degrades open. This automatic multiplication is used only for bucket families with one unique action cost. Strict and panic scaling keep a two-action credit floor for ordinary buckets so at least two legitimate costed requests fit in every derived profile window. Suspicious probes and scheduler triggers intentionally keep their explicit single-action floors.
- The first Admin setting for rate limiting is a single Owner-gated Security setting with four modes: `off`, `standard`, `strict`, and `panic`. `standard` is the default. `strict` and `panic` derive from the standard bucket descriptors with fixed multipliers instead of duplicating every threshold by hand.
- `off` is handled by one central facade gate that returns an allowed decision without calling Symfony limiter storage. It does not disable authentication, authorization, CSRF, passive abuse signals, suspicious-probe `400` handling, audit, or diagnostics.
- Registration and password-reset success do not reset global buckets by default.
- The branch must commit initial threshold defaults from the Security policy defaults as named configuration/constants with behavior tests. Later branches may tune those defaults only with matching draft/worklog notes.
- Captcha-triggered limiter resets or `429` recovery require verified provider-backed challenge success. Provider `none`, missing-provider, and disabled-provider auto-success must not reset or refill any bucket.
- Captcha failure gets a dedicated bucket descriptor and the rate facade must expose a scoped reset interface that future verified captcha providers can call. The branch must not add dead captcha routes, providers, or unreachable workflow wiring before the captcha contract/provider branches exist.
- Website global policy uses separate deliberate burst and sustained buckets. Turbo/browser prefetch uses a separate lower-confidence observation path so speculative `GET` requests do not exhaust user-facing navigation budgets. Sensitive safe `GET` workflows such as recovery-login bypass renders and Admin export/download/diagnostic reads are classified before prefetch forgiveness, so spoofable prefetch headers cannot skip their dedicated buckets.
- Deliberate browser burst and sustained protection are explicit bucket descriptors derived from understandable product values. The technical Symfony limiter configuration may be generated or mapped from those descriptors, but review should happen against the catalogue values.
- Scheduler trigger policy must allow normal once-per-minute external cron calls in `standard`, then enforce one trigger per 15 minutes in `strict` and one trigger per hour in `panic`; task due-state logic, locks, and task policies decide whether work actually runs. This is an operational pre-auth interval guard for the exact `/cron/run` route, not an abuse/security signal source for legitimate configured cron callers. Other reserved `/cron/*` paths must not spend the scheduler interval bucket. Submitted scheduler credentials are HMAC-redacted and the scheduler interval keeps IP secondary anchoring even when a browser user session is also present, so rotating invalid query credentials cannot bypass the interval from the same source.
- API, scheduler, setup, generated-asset, profiler, and toolbar roots are raw prefixless technical route scopes: they remain locale-aware through the resolved request locale, but URL locale prefixes are not accepted as aliases for these routes. URL locale-prefix stripping is allowed only for explicit locale-prefix UI/account scopes. A localized lookalike such as `/de/cron/run` or `/de/api/v1/status` is browser/content traffic unless a real technical route is registered there, and must not spend scheduler/API buckets or receive scheduler/API JSON error shapes.
- Authenticated users receive higher ordinary navigation/API limits than anonymous visitors where a workflow does not define its own explicit bucket. Owner-owned API keys and subjects tied to an active Owner session are exempt from ordinary rate-limit rejection, except `/cron/run`, where the mutable Owner API key must still spend the scheduler bucket. Mutating API requests made with a read-only Owner API key, including credentialed `OPTIONS` preflights whose `Access-Control-Request-Method` is unsafe, must spend the write/admin bucket before the read-only denial is returned.
- Scheduler `429` responses are expected operational feedback when the external caller runs more frequently than the selected profile allows. They should not create passive security signals or extra abuse diagnostics by themselves; the scheduler caller already observes the response and can adjust its interval.
- Recovery login bypass is the exact `/user/login?bypass=1` browser `GET` path. It uses its own narrow bucket, bypasses ordinary website buckets needed to render the normal login form, and must not bypass CSRF, credential checks, login-failure accounting, audit logging, or post-login policy re-evaluation. Unsafe login submissions with `bypass=1` are still normal login attempts and spend the login workflow bucket.
- Workflows that do not exist in the current codebase receive catalogue entries only when doing so does not create dead services, routes, or unreachable tests.
- Limiter keys come only from the shared subject/client-identity resolver. Raw request headers, API-key material, usernames, email addresses, scheduler credentials, and other user-submitted identifiers must never become keys directly; workflow account subjects and scheduler credential subjects are normalized and HMAC-redacted before they can be used for login, registration, password-reset, or scheduler interval buckets.
- Limiter storage degradation is fail-open by policy: the facade allows the request, records safe Message-layer diagnostics where possible, and preserves Owner recovery instead of returning an invisible hard block. Symfony limiter state is isolated by descriptor capacity/window shape so profile changes do not reuse stale fixed-window state, and cache-backed consume operations use the configured Symfony lock factory.
- Multi-bucket decisions are planned before credits are spent. The facade checks every descriptor/subject consume candidate first and only commits the batch when all candidates still have capacity. If any candidate is already exhausted, the request is rejected against that descriptor without decrementing earlier workflow, global, or account-scoped buckets. This is an intentional lightweight all-or-nothing policy, not a cross-bucket transaction: concurrent requests may still race between pre-check and commit, but per-key limiter locking bounds that race to a single timing-dependent request, after which the exhausted bucket is visible to later pre-checks. The branch accepts that residual race instead of adding complex distributed transaction/rollback logic because it is not a practical account-bucket poisoning vector.
- Enforcement follows the Security policy order so workflow buckets, global buckets, suspicious buckets, active bans, recovery-login rendering, and Owner/Admin protections interact predictably.
- Rate-limit responses use the documented response semantics: `429`, `Retry-After` when available, family-specific HTML/JSON bodies, redacted diagnostics, and `no-store`.
- Suspicious-probe handling runs before package loading and response-producing API availability, setup redirect, and maintenance gates. Probe responses force the shared minimal bare `400 Invalid Request` HTML shape for browser probes while the response-time passive signal recorder can still persist the security signal. Profile scaling extends the rejection window while preserving the one-probe action floor: `standard` 10 minutes, `strict` 15 minutes, and `panic` 20 minutes.
- This branch owns `no-store` behavior for rate-limit, block-adjacent recovery, browser/API/scheduler error, and sensitive retry responses it touches. It should also carry the production HTTP security-header follow-up forward to a dedicated response-hardening/frontend-delivery slice: define and test CSP, `frame-ancestors`, `Referrer-Policy`, `Permissions-Policy`, `X-Content-Type-Options`, sensitive-route `no-store`, and documented route exceptions without broadening this branch into a full frontend policy rewrite.
- Threshold/window configuration should be represented through named policy descriptors with units, defaults, min/max bounds, disabled behavior, and diagnostics labels, even if the first implementation keeps those descriptors as code constants.
- Configurable threshold windows must not exceed the retention of the evidence they evaluate. If a bucket or mixed-signal policy depends on database projections, security signals, IP buckets, or audit context with shorter retention, validation must reject or clamp longer windows and expose a clear diagnostic rather than evaluating missing historical data.
- Valid anonymous CORS preflights should be cheap and must not spend mutating API budget. `OPTIONS` requests that carry any non-empty `Authorization` header are credentialed preflights, not anonymous browser preflights, must not be short-circuited by the CORS responder, and must spend the matching API read/write/admin bucket by `Access-Control-Request-Method` when the request reaches rate enforcement. API authentication itself remains Bearer-only; unrelated schemes may still be anonymous only for endpoint-defined public reads on non-preflight requests.
- The API integration should choose the smallest reviewable ordering that preserves the policy: valid CORS preflights stay cheap, ordinary API reads/writes are charged after authentication has resolved valid API-key subjects and before authorization failures where practical, invalid API credentials charge stable Visitor/IP fallback buckets through authentication-failure handling, and existing API availability/error boundaries remain stable.
- High-impact authenticated/admin operations should use explicit action costs or workflow buckets where the current codebase exposes them. Owner ordinary-rate-limit exemption does not remove workflow confirmation, Admin/Owner action authorization, audit, or redaction requirements.
- Setup finalization/apply attempts need a dedicated workflow bucket because this surface exists before normal Owner/Admin session protections are available. Before `APP_SETUP_COMPLETED`, the rate-limit subscriber must not call Config, DBAL, subject resolution, limiter storage, or content-backed error rendering for ordinary setup wizard traffic; only static default probe-path matching and the exact final `POST /setup/review` `_setup_action=apply` limiter path may run. The final apply limiter may resolve the rate-limit mode through the DB-ready/default-backed Config fallback and use cache/lock limiter storage, while setup-time probe responses and setup-apply `429` responses must use the shared DB-free minimal HTML `no-store` error-renderer bare response path. Shared browser rendering for all known `4xx`/`5xx` statuses must also stay minimal and DB-free until setup completes.

## Edge cases

- Multiple buckets may be consumed for one request; rejection should report the most user-relevant failed policy without leaking all internal counters.
- If a request has no explicit primary descriptor and is not otherwise excluded, Browser/Admin/Editor traffic should still fall back to the global website burst/sustained buckets. This keeps descriptor gaps such as safe Admin navigation from becoming unlimited crawl/refresh surfaces.
- Unsafe login submissions consume the login workflow bucket through the authentication-failure event when credentials fail, including manual `POST /user/login?bypass=1` requests; unsafe invalid API credentials consume stable Visitor/IP fallback buckets through the same authentication-failure path, including high-impact Admin API mutation families. Safe login, registration, and password-reset form renders do not spend workflow-specific buckets. Submitted-account workflow buckets always consume local Visitor/IP guards before HMAC-redacted account/email subjects so a source that is already locally blocked cannot poison other users' shared account buckets. Account-token workflows such as `/user/invitation/{token}` and `/user/reset-password/{token}` use HMAC-redacted token subjects so the same leaked token is shared across visitors without exposing token material or requiring database lookup during subject resolution. Recovery-login bypass renders are the explicit exception: `/user/login?bypass=1` `GET` spends the dedicated recovery-login bucket while bypassing ordinary website buckets. Successful login resets only the login-attempt bucket for the same subject keys, including HMAC-redacted submitted-account keys, and the active rate profile.
- Read-only API keys hitting write routes should still follow API write policy before or alongside authorization failure as decided by the handler order.
- CORS preflight storms should not block legitimate configured browser clients through the write limiter, but invalid origin/header/method scans should remain visible to abuse diagnostics.
- `/api/live/**` operation polling must continue to function during long admin operations, but high-signal suspicious probe paths below `/api/live/**` must still reach the early probe blocker and return the generic `400`.
- Export/download/log/support-bundle endpoints must use `no-store`, redaction, and permission checks even when the rate limiter allows them.
- Concurrent failures and immediate success/reset sequences must not accidentally reset unrelated global buckets or hide suspicious mixed-action behavior.
- HTML `429` pages may render a captcha recovery step only when an active provider can render and validate a real challenge. Without that provider, use ordinary retry-after behavior.

## Tests and validation

- Test each guarded workflow below and above threshold.
- Test global burst and sustained website budgets catch mixed suspicious actions without counting static assets or ordinary `/api/live/**` polling.
- Test Turbo/browser prefetch does not exhaust deliberate website buckets, does not bypass sensitive recovery/export/download classifications, and still records passive signals for excessive speculative traffic.
- Test scheduler triggers allow normal minutely cron calls while still limiting repeated trigger attempts by stable redacted scheduler credential, even when cookies or user agents change, and by IP secondary anchoring when invalid submitted scheduler credentials rotate under an authenticated browser context.
- Test setup apply, CORS preflight, high-impact admin operation, Admin-vs-Owner authority outcomes, export/download, and upload/archive validation classification attach to the expected buckets when those workflows exist. Setup wizard navigation/database-test/backtracking posts before final review apply must not spend the setup-apply bucket or invoke DB-backed rate-limit services before setup completion; the exact final apply post must still reach the setup-apply limiter.
- Test authenticated-user higher limits and Owner ordinary-rate-limit exemptions for active sessions and Owner-owned API keys, plus the explicit read-only Owner API key write-denial exception for both actual unsafe requests and credentialed preflights with unsafe requested methods.
- Test that valid authenticated browser/API requests are evaluated after Symfony authentication, while failed login/API credentials still spend stable workflow buckets through authentication-failure events.
- Test recovery-login bypass rendering through the normal request stage, dedicated recovery bucket exhaustion, retry-after behavior, and successful-login policy re-evaluation.
- Test policy descriptor validation for invalid, missing, overly permissive, and overly restrictive threshold/window values where configuration is introduced.
- Test profile resolution for `off`, `standard`, `strict`, and `panic`, including the central `off` facade gate that performs no limiter consume.
- Test that strict and panic profile values derive from the standard catalogue descriptors through documented multipliers.
- Test that configurable limiter and mixed-signal windows are rejected or clamped when they exceed the retained evidence required by that policy.
- Test successful login resets only the login bucket, including the submitted-account key used by failed login enforcement.
- Test verified captcha success can reset only the configured scoped bucket, while provider `none`/missing/disabled success resets nothing.
- Test the captcha failure bucket descriptor and the dormant scoped reset interface without wiring a non-existing captcha provider.
- Test captcha-on-`429` is unavailable without an active provider and falls back to retry-after behavior.
- Test `/api/live/**` never receives ordinary rate-limit `429`.
- Test browser HTML and API/scheduler JSON `429` shapes, including path-boundary checks so lookalike browser content such as `/cronjobs` does not receive scheduler JSON.
- Test response cache headers and redaction for browser/API/scheduler limit failures.
- Test that any `no-store` headers added in this branch are route-scoped and do not claim to complete the full production HTTP security-header policy until the dedicated response-hardening/frontend-delivery slice defines CSP and related headers.
- Test that non-existing optional workflows are not wired as dead routes/services and that later workflow branches have a clear catalogue attachment point.
- Test limiter storage degradation, profile-isolated limiter state, and locked consume behavior for the highest-risk workflows.
- Test configured limiter service wiring with `lint:container`.

## Documentation and tracking

- Update Security draft thresholds and reset behavior.
- Update Security policy defaults if implementation evidence changes any threshold, subject, or reset policy.
- Update the existing Security settings page with the Owner-gated rate-limit mode setting and matching translations when the implementation lands.
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
- Browser/API/scheduler rate-limit responses are redacted, include only safe request references, and use `no-store` plus `Retry-After` where available.
