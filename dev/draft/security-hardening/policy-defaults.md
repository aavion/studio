# Security policy defaults

> **Status**: Draft  
> **Updated**: 2026-06-17  
> **Owner**: Core  
> **Purpose:** Define first implementation defaults for Security hardening branches before runtime work begins.  

Back to [security hardening implementation plan](../0.2.x-SecurityHardeningPlan.md).

## Goal

This document records the first testable Security policy defaults for the `feat-security-*` branch tree. These defaults should be implemented as named constants or configuration values in the owning branch, covered by behavior tests, and updated here whenever implementation evidence changes the policy.

The defaults are not an Admin UI requirement. Admin-configurable policy can be added later where the owning branch explicitly introduces settings, validation, documentation, and safe bounds.

## Policy Precedence

- Current product decisions in the Security hardening plan and this policy file take priority over historical `sec-lookup` behavior.
- Repository privacy rules take priority over convenience: IP-derived data remains short-retention data even when it would be useful for longer investigations.
- Runtime code should use one shared subject/client-identity resolver, one injectable clock/time boundary, and one abuse/rate facade instead of reimplementing policy in controllers.
- Any policy that would block authentication, Owner recovery, scheduler access, or Admin diagnostics must include an explicit recovery path and tests.

## Identity And Privacy

- Raw IP addresses, IP buckets, and stable IP-derived hashes may be queryable for at most 30 days across file logs, database projections, diagnostics, exports, support bundles, and backups.
- Longer-term correlation uses internal visitor ID, authenticated user UID, API key fingerprint/prefix, or aggregate dimensions.
- Visitor-ID-backed policy is preferred for continuity. IP-backed policy is a short-lived secondary layer to reduce cookie-reset bypasses and shared-host abuse.
- Raw credentials, raw API keys, raw visitor-cookie tokens, session IDs, full user agents, and captcha answer material must not be stored in policy records.
- GeoIP values are operational metadata. They may support diagnostics and aggregate statistics, but they do not create allow/deny decisions in this policy slice.
- Browser storage may hold only transient UI state, such as operation overlay resume data. It must not hold raw credentials, API keys, captcha answers, remember-me token material, CSRF secrets beyond Symfony's intended browser-side double-submit flow, or live-operation polling tokens longer than the underlying operation TTL.

## Retention Defaults

- Raw access/security file logs: 30 days by default.
- Queryable IP-derived records in database projections or passive-signal stores: maximum 30 days.
- IP-derived auto-ban records: maximum 7 days, even though the privacy ceiling is 30 days.
- Visitor-ID auto-ban records: maximum 30 days unless a later policy explicitly defines longer visitor retention and user-facing privacy copy.
- Passive suspicious signals: default 7 days for visitor/user/API subjects; default 24 hours for IP-only subjects; maximum 30 days for any IP-derived subject.
- Captcha challenge state: 15 minutes, one-shot invalidation after every validation attempt.
- Remember-me trust window: seven days.
- Account invitation/registration links: 24 hours by default. Password-reset links: one hour.

## Enforcement Order

Runtime enforcement must use one deterministic order so the same request is not handled differently by unrelated branches:

1. Resolve trusted client identity, visitor identity, request family, request intent, and safe pre-auth subject keys; resolve authenticated session/user and API key context before ordinary rate-limit decisions.
2. Apply static asset, generated asset, setup/maintenance, and `/api/live/**` classification before ordinary website/API rate decisions.
3. Resolve active Admin/Owner context before ordinary ban and rate checks so recovery protections and ordinary rate-limit exemptions can be evaluated safely.
4. Allow the recovery-login bypass path to render the normal login form before active visitor/IP bans or exhausted ordinary website buckets block it, while still applying the dedicated recovery-login bucket.
5. Classify high-signal probes early and return the generic probe response without revealing route existence.
6. Check active bans except where Admin/Owner protection or the recovery-login rendering rule applies.
7. Consume rate buckets in a stable order: workflow-specific bucket, request-family/global bucket, then suspicious/abuse bucket where applicable.
8. When multiple buckets fail, report the most user-actionable policy to the client and keep internal bucket names in diagnostics only.
9. Run the guarded workflow only after the decision is allowed.
10. Apply scoped resets only after clear success, such as successful credential login or verified provider-backed captcha success.
11. Record audit events and passive signals with redacted context regardless of whether the request was allowed or blocked.

Owner/Admin protection does not bypass authentication validity, account status, role checks, ACL decisions, CSRF validation, API-key revocation, or explicit workflow authorization. Exempted requests should still record diagnostics so unusual administrative traffic remains reviewable.

## Admin And Owner Authority

`Admin` is a delegated operations role. `Owner` is the site-control role. Admins may enter the Admin area, but high-impact actions need an explicit action policy instead of inheriting blanket mutation rights from the `/admin` route prefix.

Default authority policy:

- Admins may view normal Admin dashboards, package/theme overviews, scheduler status, redacted log/audit/security diagnostics, non-secret settings, user review queues, and operational summaries.
- Admins may mutate non-owner user accounts, ACL groups below their own role level, pending account-token review actions, password-reset link creation, bounded non-secret settings, cache/asset rebuilds, and trusted registered scheduler run-now actions when the owning workflow allows it.
- Owners are required for Owner/Admin account promotion or demotion, peer Admin changes, last-Owner-sensitive actions, protected secret configuration, security policy bounds, public API/CORS expansion, scheduler web-trigger/GET-token enablement, package install/activate/purge/update, backup restore, backup/download/export of full system data, self-update/release actions, destructive package/data purge, and emergency operational controls that can affect global runtime state.
- Admins may perform manual unban or abuse review for ordinary anonymous/user subjects, but Owner/Admin subject relief, disabling auto-ban, weakening recovery protections, or changing privacy ceilings remains Owner-only.
- Protected values remain write-only or status-only even for Owners unless a workflow explicitly implements a reveal flow with re-authentication, audit, and redaction rules.
- Permission-aware navigation is not the security boundary. Controllers, API handlers, live-operation starters, scheduler triggers, and service-layer workflows must all call the same action policy before mutating or revealing high-impact data. Responsibility decides the feature row: pending account-token review actions use the review permission even when rendered from user management, while direct user creation/editing/group membership uses the user-management permission.

ACL rules are assigned by operational responsibility, not necessarily by the current view, menu, or route area that exposes a control. For example, editing ACL group definitions belongs to `admin.users.acl`, while adding or removing groups on a user account mutates that user account and belongs to `admin.users`. Review actions stay under their review permission even when rendered from a broader user-management view, so approving or rejecting pending registrations belongs to `admin.users.review`. Likewise, confirming a reviewed live operation must re-check the target operation's domain feature, such as `admin.packages`, instead of relying only on the Operations view permission.

The first implementation should introduce a route/action authority matrix or policy service for existing Admin surfaces instead of relying on ad hoc controller checks. Existing user-management guardrails remain the model for peer-role and last-Owner protection, but they are not sufficient for package, scheduler, backup, settings, diagnostics, and update workflows.

## Rate-Limit Defaults

These are first implementation defaults. Branches may adjust them only with tests and a worklog note explaining the review reason.

Rate-limit implementation must keep action costs separate from bucket budgets. The action-cost catalogue assigns stable semantic costs to request intents, while a dedicated rate-limit policy catalogue owns bucket descriptors, capacities, windows, TTL/retry metadata, reset eligibility, diagnostics labels, and profile scaling. This keeps later tuning centralized and allows future config-backed thresholds to attach at the policy-catalogue boundary without changing classifiers, subscribers, or controllers.

Descriptor capacities are implementation credit budgets generated from the action counts shown in the policy table. When a bucket family has one unique action-cost value, the rate-policy catalogue multiplies the documented action count by that cost so Symfony `consume(n)` never asks a limiter to consume more tokens than the bucket can hold. Strict and panic profile scaling keeps a per-bucket minimum for two legitimate costed requests, so even the strongest profile still allows normal one-shot workflows without degrading open. Suspicious probes and scheduler triggers are explicit single-action exceptions because their profile behavior is interval-based.

The first Admin-facing rate setting is one Owner-gated Security setting with four modes:

- `off`: central facade gate allows requests without calling limiter storage. Authentication, authorization, CSRF, suspicious-probe `400` handling, passive abuse signals, audit, and diagnostics remain active.
- `standard`: default policy values from the bucket catalogue.
- `strict`: derived from `standard` by fixed multipliers that reduce capacity and/or extend windows/retry floors for elevated pressure.
- `panic`: derived from `standard` by stronger fixed multipliers for temporary emergency pressure.

`strict` and `panic` must be calculated from the standard bucket descriptors instead of duplicating every bucket threshold. The derived values must be covered by tests so the code shows exactly how much capacity and retry behavior changes in each mode. `Retry-After` should use Symfony limiter metadata where available; descriptor-provided retry values act only as documented floors or special-case overrides such as recovery login.

| Policy | Default | Subject | Success reset |
| --- | --- | --- | --- |
| Login failures | 5 failed attempts per 15 minutes | HMAC-redacted submitted username/email plus Visitor ID and IP bucket | Successful credential login resets only the login-attempt bucket for the same submitted-account/visitor/IP subjects |
| Recovery login bypass | 2 recovery-login requests per minute, 10 per hour, retry after 30 minutes once exhausted | HMAC-redacted submitted username/email plus Visitor ID and IP bucket | Successful credential login re-evaluates active bans/limits under authenticated policy |
| Registration submissions | 3 submissions per hour and 10 per day | HMAC-redacted submitted email or invitation token plus Visitor ID and IP bucket | No automatic global reset |
| Password-reset requests | 3 requests per hour and 10 per day | HMAC-redacted submitted email or reset token plus Visitor ID and IP bucket | No automatic global reset |
| Contact form submissions | 3 submissions per 10 minutes and 20 per day | Visitor ID; IP bucket as secondary signal | No automatic global reset |
| Captcha failures | 5 failures per 10 minutes | Challenge subject plus visitor ID | Verified provider-backed captcha may reset the scoped challenge/form bucket only |
| Website deliberate burst | 30 deliberate browser route requests per minute | Visitor ID; IP bucket as secondary signal | No success reset |
| Website deliberate sustained | 300 deliberate browser route requests per 30 minutes | Visitor ID; IP bucket as secondary signal | No success reset |
| Turbo/browser prefetch observation | 120 safe prefetch `GET` requests per minute and 600 per 30 minutes | Visitor ID; IP bucket as secondary signal | No ordinary rejection by itself; records lower-confidence passive signals |
| Versioned API read | 600 safe requests per minute | Verified API key fingerprint after authentication, otherwise Visitor ID/IP fallback | No success reset |
| Versioned API write | 60 mutating requests per minute | Verified API key fingerprint after authentication, otherwise Visitor ID/IP fallback; submitted API-key prefixes are not primary limiter subjects | No success reset |
| Public anonymous API read | 120 safe requests per minute | Visitor ID; IP bucket as secondary signal | No success reset |
| Scheduler trigger | Standard: 1 trigger per minute; Strict: 1 trigger per 15 minutes; Panic: 1 trigger per hour | HMAC-redacted submitted scheduler credential, with IP bucket fallback/secondary subject before controller authentication, including when a user session is present | No success reset |
| Suspicious probes | Standard: 1 high-signal probe per 10 minutes; Strict: 1 per 15 minutes; Panic: 1 per 20 minutes | Visitor ID plus IP bucket | No success reset; return generic `400`; may drain suspicious buckets |

Website global buckets count application/browser route handling, not static assets, generated assets, or `/api/live/**` polling. The first implementation should enforce both deliberate website buckets: the burst bucket catches very fast click/submit loops, while the sustained bucket catches automated crawling that stays just below the per-minute limit.

`/api/live/**` remains outside ordinary rate-limit rejection. High-signal probe paths below `/api/live/**` still return the generic suspicious-probe `400`; normal live polling and captcha refreshes should not receive the normal website/API `429` path.

Turbo/browser prefetch for safe `GET` requests should not spend the same budget as deliberate navigation. Use a dedicated prefetch observation bucket or lower-confidence passive signal weighting; do not let spoofable prefetch headers bypass authentication, authorization, CSRF, domain validation, recovery-login bypass buckets, or Admin export/download/diagnostic buckets. Expensive or side-effect-adjacent links should disable prefetch rather than relying on rate-limit forgiveness.

Scheduler trigger limits must support a normal once-per-minute external cron in `standard`. `strict` and `panic` intentionally control the allowed external trigger interval instead of using the ordinary profile multiplier logic: `strict` allows one trigger per 15 minutes, and `panic` allows one trigger per hour. The scheduled tasks still use internal due-state logic, run locks, and task policies, so legitimate scheduler calls are expected and should not be treated as abuse by themselves. Scheduler interval `429` responses are operational feedback for the caller and must not create passive security signals or extra abuse diagnostics by themselves; the configured caller already logs the response and can adjust its interval. The interval bucket applies only to the exact `/cron/run` route and must use the submitted scheduler credential after HMAC redaction, with IP bucket fallback/secondary anchoring, because `/cron/run` authenticates inside the controller after the pre-controller interval guard. Other reserved `/cron/*` paths must not spend the scheduler interval bucket. Scheduler IP secondary anchoring remains active even when a browser user session is present, so rotating invalid query credentials cannot create fresh interval buckets from the same source.

Registered authenticated users receive higher limits than anonymous visitors where the workflow is not already account-specific. The first default is a 2x multiplier for deliberate website navigation and public-read style API usage after the request resolves to an active authenticated user. Login, registration, password-reset, captcha, scheduler, and suspicious-probe policies keep their explicit workflow limits.

Owner-owned API keys and Visitor-ID/IP subjects that resolve to an active Owner session are exempt from ordinary rate-limit rejection, except for the scheduler trigger surface where a mutable Owner API key is the expected credential and the configured scheduler interval must still be enforced. A mutating API request made with a read-only Owner API key is also not ordinary allowed Owner traffic; it must spend the API write/admin bucket before the read-only denial is returned. Credentialed `OPTIONS` preflights use `Access-Control-Request-Method` for this decision, so read-only Owner keys cannot bypass write/admin buckets by probing unsafe routes through transport-level `OPTIONS`. Owner traffic may still record diagnostics and passive signals, but the request path must preserve Owner recovery and administrative operation access outside those explicit exceptions.

Limiter storage degradation is fail-open by policy. If limiter storage, locking, or consume/reset operations fail, the facade should allow the request, emit safe Message-layer diagnostics where possible, and avoid creating an invisible Owner, login, setup, API, or scheduler lockout.

Symfony limiter storage keys must be isolated by the active descriptor shape, including profile-derived capacity/window values, so changing between `standard`, `strict`, and `panic` does not reuse stale fixed-window state. Cache-backed limiter consumption should use the configured Symfony lock factory so concurrent failed credentials or API requests cannot race through the same remaining budget.

Multi-bucket requests must not partially spend earlier buckets when a later bucket rejects the request. The facade should pre-check all planned descriptor/subject candidates, then commit only when every candidate still has capacity. This preserves account-scoped workflow protection without letting a visitor that is already blocked by local or global website budgets poison other users' shared submitted-account buckets. This policy deliberately avoids a cross-bucket transaction manager: concurrent requests can race between pre-check and commit, but Symfony per-key locking bounds that race to a timing-dependent request, and subsequent requests see the exhausted bucket during pre-check. That residual race is accepted as simpler and reviewable because it does not provide a practical way to repeatedly drain unrelated account buckets.

## Probe Path Policy

- Probe paths are configurable as an editable pattern list, not as raw JSON. The default UI should use one regular expression per line and may accept quoted CSV imports; unquoted newline entries must be preserved as-is so commas inside regex syntax remain valid. The shipped defaults cover high-signal requests such as `.env`, `.git`, backup archives, database dumps, common admin panels from other software, shell upload probes, and known scanner paths.
- High-signal probes are never treated as normal website navigation. The default response is a generic `400 Bad Request` without revealing whether the path exists, and the event records a suspicious probe signal. Probe blocking must run before response-producing availability, setup, maintenance, live/API, and ordinary technical-exclusion gates so exposed install or disabled-feature surfaces cannot bypass the hardened response.
- One high-signal probe per subject per 10 minutes is the first threshold. Strict and panic extend that window while keeping a single-probe credit floor so profile scaling cannot produce an unusable capacity below the suspicious-probe action cost. Further probes may drain suspicious buckets and feed auto-ban decisions when auto-ban is enabled.
- Honeypot probe paths should remain restrictive. They may share the same generic `400` response and signal path even when they do not map to real routes.
- Probe-path configuration should use anchored, normalized path patterns with tests that prove common application routes, package routes, media routes, and editor routes are not accidentally captured.
- Probe-path configuration changes should be auditable once Security settings exist.
- Editor/Content route editing should warn, without blocking the save, when a proposed route or slug would match a configured suspicious probe path. This keeps legitimate content possible while making accidental collisions visible before publication.

## Response Semantics

- Rate-limit exhaustion returns `429 Too Many Requests` with `Retry-After` when a reliable retry time exists.
- Active temporary bans return a generic `403 Forbidden` by default, also with `Retry-After` when the ban expiry is known. The response must not expose raw reason internals, subject keys, IP data, or bucket names.
- High-signal probes return generic `400 Bad Request` and must not reveal whether a probed path, file, or package exists. Probe handling should run before package loaders and other response-producing request gates, then force a minimal `400 Invalid Request` HTML response for browser probes while leaving the passive response-time signal recorder able to persist the security signal.
- Browser responses use the shared HTML error/recovery renderer. Versioned API, scheduler, and JSON-request responses use the stable JSON error shape for their request family.
- Security block, recovery, captcha, login, and bypass responses are `no-store` by default. Shared rendered HTTP error pages also set `no-store` centrally so customized system error content cannot be cached accidentally.
- `/api/live/**` should return cheap JSON, token/access checks where needed, `no-store`, and passive signals; it should not enter ordinary website/API `429` rendering.

## Additional Security Surface Coverage

The codebase and other feature drafts expose several security-relevant surfaces beyond login, captcha, API, scheduler, and probes. The first Security branches should cover them through classification, cost catalogues, diagnostics, or explicit deferred follow-ups rather than inventing separate local policies later.

- Setup/install mode is its own request family. Before setup completion, rate limiting must not touch Config, DBAL, subject resolution, limiter storage, or content-backed error rendering for ordinary setup wizard traffic. The exact final review-step apply submission (`POST /setup/review` with `_setup_action=apply`) is the only setup request that may reach the setup-apply limiter before setup completion; it may resolve the mode through DB-ready/default-backed Config fallback and use cache/lock limiter storage. Wizard navigation, language, site, database-test, admin, and backtracking posts must not spend the setup-apply bucket. Static default suspicious-probe matching may still return a DB-free minimal HTML `400 no-store` response before setup completion, and setup-apply `429` responses before completion must also stay DB-free and `no-store`. Shared browser rendering for all known `4xx`/`5xx` statuses must return minimal HTML `no-store` responses before setup completion instead of resolving custom system error content. After setup completion, setup routes must not become public alternative admin entry points. Setup ActionLog/live-operation payloads must stay tokenized, `no-store`, and redacted.
- CORS preflight and API metadata requests are API-family traffic, not write attempts. Successful allowed anonymous `OPTIONS` preflights should be cheap and must not spend mutating API budget; invalid origin/method/header combinations may record passive signals. `OPTIONS` requests that carry any non-empty `Authorization` header are credentialed preflights and must be classified by `Access-Control-Request-Method` for rate limits. Invalid Bearer credentials must spend the matching API read/write/admin authentication-failure bucket and must never fall back to anonymous public reads; unrelated non-Bearer schemes remain anonymous only for endpoint-defined public reads on non-preflight requests.
- Technical roots such as `/api`, `/api/live`, `/cron`, `/setup`, generated assets, the profiler, and the toolbar are raw prefixless path scopes: they remain locale-aware through the resolved request locale, but URL locale prefixes are not accepted as aliases for these routes. A localized content or UI path that looks like `/de/api/...` or `/de/cron/run` must not inherit API, scheduler, setup, static-asset, or JSON-response behavior unless an actual technical route is registered there.
- High-impact authenticated/admin workflows need explicit intents and authority decisions even when Owner requests are exempt from ordinary rate-limit rejection: settings mutations, user/ACL changes, package install/activate/purge, backup restore, import apply, export/download, cache or asset rebuild, self-update, scheduler run-now, and diagnostic/support-bundle generation. Trusted registered Scheduler tasks are authorized by the Scheduler feature; live-operation continuations remain authorized by their target-domain feature before follow-up work starts.
- Upload and archive handling, including media, package ZIPs, import bundles, backups, and restore artifacts, should not be treated as suspicious probe traffic by path alone. Failed extension, MIME, size, path traversal, nested archive, and manifest-validation checks should feed passive signals with redacted context.
- Public-facing unsafe form submissions that are not covered by a more specific workflow remain their own `website_form` bucket. This includes future package-owned public forms such as comments, forum posts, ratings, or similar user-generated content actions.
- Log views, diagnostic downloads, exports, backups, and support bundles must be permission-aware, `no-store`, redacted, and retention-aware. They must not expose raw IP data beyond the 30-day ceiling or raw tokens/secrets through downloadable output.
- Security-signal visibility, IP-bearing access-log visibility, signal cleanup/mutation, and future review actions need explicit Owner/ACL policy in `feat-security-admin-acl-enforcement` instead of relying indefinitely on broad Admin-area access.
- Trusted proxy handling is a deployment/webserver boundary, not an app-level Security settings feature. Security identity, GeoIP, IP-bucket policy, access logs, API diagnostics, and auto-ban decisions must use Symfony's resolved request client IP and must not trust raw forwarding headers directly. Operators configure trusted reverse proxies through webserver/Symfony deployment config, for example `mod_remoteip` or equivalent server-level handling.
- Visitor ID generation may use raw forwarding-header values only as untrusted differentiation entropy, for example to avoid merging unrelated browsers behind the same resolved IP when their `X-Forwarded-For` chains differ. Raw forwarding-header values must not become Security subject keys, GeoIP inputs, ban keys, or signal evidence. Because clients can spoof those headers, enforcement must not rely on the fallback Visitor-ID alone for anonymous cookie-less abuse; it must evaluate the stable IP-bucket HMAC alongside Visitor-ID evidence.
- Visitor ID remains the preferred browser continuity key. Different browsers behind the same untrusted proxy should still receive separate visitor subjects. IP bans/blocks remain allowed as a secondary cookie-reset bypass defense, but their thresholds should be laxer than Visitor-ID thresholds so shared or untrusted-network IPs have a lower false-positive risk.
- HTTP security headers are an adjacent production-hardening follow-up. Before production readiness, define and test the response policy for CSP, `frame-ancestors`, `Referrer-Policy`, `Permissions-Policy`, `X-Content-Type-Options`, sensitive-route `no-store`, and any route-specific exceptions needed by the editor, package assets, or external integrations.
- Configurable enforcement windows, thresholds, escalation windows, and review horizons must respect the retention of the underlying evidence. A limiter, auto-ban, or review policy may not evaluate signals, projected logs, IP-derived buckets, or other evidence beyond the configured retention window for that data. If an operator configures an enforcement window longer than the available retained evidence, the implementation must reject, clamp, or clearly diagnose the mismatch instead of pretending older evidence can still be considered.

## Auto-Ban Defaults

- Auto-ban is enabled by default and can be disabled through Security policy/settings once the auto-ban branch introduces bounded configuration.
- Visitor-ID bans are the preferred continuity mechanism:
  - first temporary ban: 1 hour;
  - repeated ban within 24 hours: 24 hours;
  - severe repeated anonymous abuse: up to 7 days;
  - maximum: 30 days unless a later policy extends visitor retention.
- IP-bucket bans are secondary and shorter:
  - first temporary IP ban: 15 minutes;
  - repeated IP ban within 24 hours: 6 hours;
  - severe repeated IP abuse: up to 24 hours;
  - maximum: 7 days.
- IP-ban/block thresholds should stay laxer than Visitor-ID thresholds because one resolved IP may represent multiple users behind shared hosting, NAT, or an untrusted proxy. Use Visitor-ID evidence first where available, and treat IP-only evidence as a secondary escalation signal unless the signal is severe.
- API-key bans use key fingerprint/prefix only:
  - invalid-key probe ban: 15 minutes;
  - repeated invalid-key probe ban: 1 hour;
  - compromised or revoked-key replay review may escalate to 24 hours.
- Authenticated users start with higher limits and softer handling such as throttling, captcha, warnings, or session/token review unless explicit compromise signals justify a hard block.
- Visitor IDs and IP buckets that resolve to an active Admin or Owner session must not be banned.
- API keys owned by an active Owner and Visitor-ID/IP subjects that resolve to an active Owner session must not be rate-limited by ordinary application buckets.
- Owner accounts must retain at least one documented recovery path. A policy that could deny all Owners is invalid.
- Provide a recovery login path such as `GET /user/login?bypass=1` that renders the normal login form even when the current Visitor ID or IP bucket is banned or ordinary website buckets are exhausted. The bypass flag only bypasses ban/rate checks that would prevent rendering the login form; it does not bypass CSRF, credential validation, login-failure accounting, the dedicated recovery-login bucket, audit logging, or post-login policy re-evaluation. Unsafe login submissions with `bypass=1` remain normal login attempts.
- The dedicated recovery-login bucket is intentionally small but not lockout-like: 2 recovery-login requests per minute, 10 per hour, and a 30-minute retry window after exhaustion.
- Manual unban takes effect immediately and must be audited.

## Captcha Defaults

- `none`, missing provider, and disabled provider validate successfully until a workflow explicitly introduces provider-required policy.
- `none`, missing-provider, and disabled-provider success means "do not block the workflow because captcha is unavailable"; it is not verified human challenge success.
- IconCaptcha challenge TTL is 15 minutes so humans can complete longer forms without unnecessary expiry.
- Every validation attempt consumes the challenge ID, successful or failed.
- The longer challenge TTL is safe only when repeated guesses against the same challenge are impossible. Keep one-shot invalidation, context binding, tight captcha-failure buckets, refresh abuse signals, and answer-leak tests in place.
- Captcha success may reset only the scoped challenge/form bucket when the workflow policy allows it and when a real provider validated a real challenge.
- Provider `none`, missing-provider, or disabled-provider auto-success must never reset rate-limit buckets, refill budgets, clear bans, or satisfy a captcha-based `429` recovery step.
- Rendering captcha on a `429` recovery/error page is allowed only when the workflow has an active captcha provider that can validate a real challenge. Without such a provider, the response must fall back to ordinary retry-after behavior.
- Visual IconCaptcha must not expose answer-bearing names through DOM, SVG, asset paths, translation keys, hidden text, or ARIA labels.
- If neutral labels are not sufficient for assistive technology, the preferred fallback is a provider-owned quiz challenge that shares the same one-shot, TTL, context-binding, refresh, and abuse-signal rules.

## Logging And Projection Policy

- Rotating file logs remain the durable raw operational source.
- Database-backed message, audit, and access lookup projections are the Admin/API read model for query-heavy review and abuse correlation.
- Passive security signals are stored separately as `security_signal_event` rows with explicit expiry and remain observational until later enforcement branches consume them.
- Session/visitor mismatches that already terminate an authenticated session are high-risk passive signals. They may feed later rate-limit, auto-ban, account-review, or recovery diagnostics, but copied session plus copied visitor-cookie risk scoring still needs additional policy in the later Security/remember-me work.
- The projection must duplicate only minimized/redacted structured fields, never full raw log lines, keep IP-derived data within the 30-day limit, purge expired rows after successful writes, and degrade without weakening enforcement or hiding file-log diagnostics.
- Level/severity fields are stored only where they support meaningful filtering: message projections keep level, security signals keep severity, and current access/audit projections omit level fields.
- Backups, exports, diagnostics, and support bundles must not silently extend IP retention.

## Configuration Posture

- First implementations may ship policy defaults as code-level constants or configuration values with tests.
- Admin-configurable settings require bounded validation, safe defaults, documentation, and tests for disabled/missing settings.
- Security policy bounds must prevent accidental lockout and privacy drift. Configuration must not allow log projection or shared security-signal retention above 30 days, IP-ban TTLs above the documented maximum, disabling Owner recovery, disabling the recovery-login bypass without an equivalent path, or treating captcha `none` auto-success as verified human success.
- More permissive settings for public entry points should require an explicit policy update, not only a local configuration change.
- More restrictive settings that affect login, account recovery, scheduler operation, captcha, mail delivery, or Owner/Admin access need tests for recovery behavior and false-positive handling.
- User-facing copy is required whenever a configurable policy affects public behavior, recovery, captcha, mail delivery, remember-me, account access, or data retention.

## Configuration Surface Defaults

These are first soft decisions for which values should stay fixed, become protected environment/config values, or become audited Admin settings later. A branch may choose code constants for its first implementation, but it should keep the target surface in mind so later configuration does not require redesign.

| Area | First implementation surface | Later configurable? | Boundaries |
| --- | --- | --- | --- |
| Enforcement order, Owner recovery, Admin/Owner lockout protection | Code-level policy and tests | No ordinary Admin setting | Requires a policy update and explicit recovery tests to change |
| IP privacy ceiling and raw-secret redaction | Code-level policy and tests | No increase allowed | IP-derived data max 30 days; raw credentials, API keys, visitor tokens, session IDs, captcha answers, and full user agents are never policy records |
| Raw file-log retention | Existing log configuration or code default | Yes, bounded | Default 30 days; IP-bearing logs must not become queryable beyond 30 days through archives, projections, exports, or support bundles |
| Database log projections and security signals | Config-backed defaults in Abuse Foundation | Yes, bounded | Message/audit/access projections default to 30 days; all passive security signals default to 7 days through one shared signal-retention setting; all IP-derived/queryable projection data remains capped at 30 days |
| GeoIP enablement, database path, license key, and update task | Protected config/Admin setting with null fallback | Yes, protected and audited | License key never public; disabled/unconfigured state uses `NullGeoIpResolver`; no geo-blocking |
| GeoIP license key | Secret/protected setting | Yes, protected only | Never rendered, exported, logged, or included in diagnostics |
| Probe-path defaults | Code defaults plus config descriptor | Yes, audited | Defaults remain broad; patterns are anchored/normalized and tested against false positives |
| Auto-ban enabled flag | Code default `on` | Yes, bounded | Disabling requires diagnostics; cannot disable Owner recovery, audit, or passive signal recording by accident |
| Auto-ban TTLs and escalation windows | Code/config defaults | Yes, bounded | No permanent bans; IP-ban TTL stays below the documented max and IP retention ceiling |
| Rate-limit mode | Owner-gated Security setting with `off`, `standard`, `strict`, and `panic` | Yes, bounded to those modes first | `off` bypasses limiter consume calls only; suspicious probes, passive signals, auth, ACL, CSRF, audit, and diagnostics stay active |
| Rate-limit thresholds and windows | Dedicated code-level policy catalogue plus derived profile scaling | Yes, later at the catalogue boundary | Lower values that affect login, scheduler, captcha, or recovery require false-positive/recovery tests; higher public-entry values require policy review; future config-backed tuning should attach at the catalogue boundary |
| Setup apply/finalization bucket | Named code/config default | Yes, bounded | Must avoid installer lockout; stricter values need documented CLI/manual recovery |
| High-impact admin action costs | Action-cost catalogue constants | Possibly later | Authorization, confirmation, audit, and redaction stay mandatory; Owner ordinary-rate exemption does not bypass workflow safety |
| Admin/Owner action authority matrix | Code-owned registry plus seeded `acl.admin.features` defaults | Owner-only bounded `Settings/ACL` overrides for descriptor-approved rows | Navigation is not enforcement; Owner-only actions require service/API/live-operation checks; unsafe delegation remains invalid; ACL groups may explicitly grant or restrict specific permissions only after the relevant surface gate is satisfied |
| Authenticated-user multiplier | Code/config default | Yes, bounded | Applies only to ordinary navigation/public-read usage, not explicit workflow buckets |
| Owner ordinary-rate-limit exemption | Code-level policy and tests | No ordinary Admin setting | Does not bypass authentication, authorization, API-key revocation, CSRF, audit, or diagnostics |
| Recovery-login bypass path and bucket | Code/config default | Path and thresholds may be bounded later | Must keep an equivalent Owner/Admin recovery path; bypass never skips credential, CSRF, audit, or failure accounting |
| Captcha provider selection and workflow map | Contract-level configuration | Yes, audited | Provider `none`/missing/disabled remains graceful success only, not verified human success |
| Captcha challenge TTL | Code/config default | Yes, bounded | Default 15 minutes; recommended range 10-30 minutes; longer values need policy review and brute-force/refresh tests |
| Captcha reset/recovery eligibility | Code-level policy and tests | No ordinary Admin setting | Only verified provider-backed success may reset scoped buckets or satisfy captcha-based recovery |
| IconCaptcha provider secret | Secret/protected setting | Yes, protected only | Never stored in package metadata, public assets, cache payloads, logs, or diagnostics |
| Remember-me trust window | Code/config default | Possibly later | Default seven days; longer windows require explicit token-rotation, revocation, visitor-binding, and privacy review |
| Scheduler trigger thresholds | Named code/config defaults | Yes, bounded | Must support minutely external cron; task due-state and locks remain authoritative |
| Mailer transport and debug log delivery | Environment/protected config | Yes, protected | Production must not depend on message-log action URLs; debug log delivery remains debug-gated |
| HTTP security-header policy | Code/config defaults | Possibly later | Defaults must be production-safe; route exceptions need tests and documentation |
| Trusted proxy/client identity | Symfony/deployment config plus resolver tests | Yes, deployment-level only | Raw forwarding headers are never trusted outside configured proxies |

Config descriptors should include the setting key, unit, default, minimum, maximum, disabled behavior, source priority, audit behavior, and safe diagnostics shape. Invalid security configuration should fail early in development/test and degrade to the safest documented behavior in production only when that degradation cannot lock out Owners or hide a security failure.

## Review Requirements

- Every branch that implements one of these policies must update this file when thresholds, TTLs, subject types, or retention rules change.
- Every branch must complete the Security PR-readiness checklist in the master hardening plan from the actual branch diff.
- Follow-up decisions that remain after implementation must be recorded in `dev/WORKLOG.md`.
