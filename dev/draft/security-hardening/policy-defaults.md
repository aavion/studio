# Security policy defaults

> **Status**: Draft  
> **Updated**: 2026-06-15  
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

## Retention Defaults

- Raw access/security file logs: 30 days by default.
- Queryable IP-derived records in database projections or passive-signal stores: maximum 30 days.
- IP-derived auto-ban records: maximum 7 days, even though the privacy ceiling is 30 days.
- Visitor-ID auto-ban records: maximum 30 days unless a later policy explicitly defines longer visitor retention and user-facing privacy copy.
- Passive suspicious signals: default 7 days for visitor/user/API subjects; default 24 hours for IP-only subjects; maximum 30 days for any IP-derived subject.
- Captcha challenge state: five minutes, one-shot invalidation after every validation attempt.
- Remember-me trust window: seven days.
- Account invitation/registration links: 24 hours by default. Password-reset links: one hour.

## Rate-Limit Defaults

These are first implementation defaults. Branches may adjust them only with tests and a worklog note explaining the review reason.

| Policy | Default | Subject | Success reset |
| --- | --- | --- | --- |
| Login failures | 5 failed attempts per 15 minutes | Visitor ID plus username/email hash where safe; IP bucket as secondary signal | Successful credential login resets only the login-attempt bucket |
| Registration submissions | 3 submissions per hour and 10 per day | Visitor ID; IP bucket as secondary signal | No automatic global reset |
| Password-reset requests | 3 requests per hour and 10 per day | Visitor ID plus normalized email hash where safe; IP bucket as secondary signal | No automatic global reset |
| Contact form submissions | 3 submissions per 10 minutes and 20 per day | Visitor ID; IP bucket as secondary signal | No automatic global reset |
| Captcha failures | 5 failures per 10 minutes | Challenge subject plus visitor ID | Successful captcha may reset the scoped challenge/form bucket only |
| Website global budget | 120 ordinary requests per minute | Visitor ID; IP bucket as secondary signal | No success reset |
| Versioned API read | 600 safe requests per minute | API key fingerprint or visitor/anonymous subject | No success reset |
| Versioned API write | 60 mutating requests per minute | API key fingerprint | No success reset |
| Public anonymous API read | 120 safe requests per minute | Visitor ID; IP bucket as secondary signal | No success reset |
| Scheduler trigger | 5 trigger attempts per minute and 30 per hour | API key fingerprint plus scheduler endpoint subject | No success reset |
| Suspicious probes | 10 high-signal probes per 10 minutes | Visitor ID plus IP bucket | No success reset; may drain suspicious buckets |

`/api/live/**` remains outside ordinary rate-limit rejection. Clear abuse on live endpoints records passive signals and may affect global suspicious handling, but live polling and captcha refreshes should not receive the normal website/API `429` path.

Turbo/browser prefetch for safe `GET` requests should not spend the same budget as deliberate navigation. Use lower-confidence passive signal weighting; do not let spoofable prefetch headers bypass authentication, authorization, CSRF, or domain validation.

## Auto-Ban Defaults

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
- API-key bans use key fingerprint/prefix only:
  - invalid-key probe ban: 15 minutes;
  - repeated invalid-key probe ban: 1 hour;
  - compromised or revoked-key replay review may escalate to 24 hours.
- Authenticated users start with softer handling such as throttling, captcha, warnings, or session/token review unless explicit compromise signals justify a hard block.
- Owner accounts must retain at least one documented recovery path. A policy that could deny all Owners is invalid.
- Manual unban takes effect immediately and must be audited.

## Captcha Defaults

- `none`, missing provider, and disabled provider validate successfully until a workflow explicitly introduces provider-required policy.
- IconCaptcha challenge TTL is five minutes.
- Every validation attempt consumes the challenge ID, successful or failed.
- Captcha success may reset only the scoped challenge/form bucket when the workflow policy allows it.
- Visual IconCaptcha must not expose answer-bearing names through DOM, SVG, asset paths, translation keys, hidden text, or ARIA labels.
- If neutral labels are not sufficient for assistive technology, the preferred fallback is a provider-owned quiz challenge that shares the same one-shot, TTL, context-binding, refresh, and abuse-signal rules.

## Logging And Projection Policy

- Rotating file logs remain the durable raw operational source.
- A database-backed security event projection is an open read-model decision for query-heavy review and abuse correlation.
- If introduced, the projection must duplicate only minimized/redacted fields, keep IP-derived data within the 30-day limit, and degrade without weakening enforcement or hiding diagnostics.
- Backups, exports, diagnostics, and support bundles must not silently extend IP retention.

## Configuration Posture

- First implementations may ship policy defaults as code-level constants or configuration values with tests.
- Admin-configurable settings require bounded validation, safe defaults, documentation, and tests for disabled/missing settings.
- User-facing copy is required whenever a configurable policy affects public behavior, recovery, captcha, mail delivery, remember-me, account access, or data retention.

## Review Requirements

- Every branch that implements one of these policies must update this file when thresholds, TTLs, subject types, or retention rules change.
- Every branch must complete the Security PR-readiness checklist in the master hardening plan from the actual branch diff.
- Follow-up decisions that remain after implementation must be recorded in `dev/WORKLOG.md`.
