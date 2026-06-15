# Auto-ban branch plan

> **Status**: Draft  
> **Updated**: 2026-06-15  
> **Owner**: Core  
> **Purpose:** Define the `feat-security-auto-ban` implementation plan.  

## Goal

Add TTL-based temporary bans for sustained suspicious anonymous/IP/visitor/API behavior, while keeping authenticated handling softer and preserving Owner recovery access.

Back to [security hardening implementation plan](../0.2.x-SecurityHardeningPlan.md).

## Git handling

Codex may create local commits for this branch when each commit has a clear thematic scope. Pushes require explicit user instruction.

## Dependencies

- `feat-security-abuse-foundation`.
- `feat-security-rate-enforcement`.
- Existing Admin, audit, message, user-role, visitor identity, and API-key foundations.

## Legacy inspiration

The old Grav plugin `sec-lookup` at `/Volumes/Projekte/temp/sec-lookup` may be reviewed for temporary-block workflows, ban-review ergonomics, and false-positive lessons. Current database-backed TTL records, authenticated soft handling, Owner recovery protection, and Admin audit requirements have priority. Do not copy legacy logic, thresholds, or persistence directly.

## Implementation sequence

1. Add database-backed ban records with subject type, normalized subject key, reason code, source signal summary, status, created/expiry timestamps, actor context where available, and manual unban metadata.
2. Add cleanup for expired bans through command and scheduler-ready task with a separate review-retention window for recently expired records.
3. Add ban-decision checks to the abuse facade after request classification and before expensive workflow handling.
4. Enforce by default for anonymous/IP/visitor/API probe abuse.
5. Apply softer authenticated handling: throttle, captcha, or warning state before hard block unless account compromise signals are explicit.
6. Add Owner safety checks so at least one active Owner retains login and recovery paths.
7. Add compact Admin review/manual unban surface with audit entries.

## Public interfaces and data decisions

- First implementation uses database-backed TTL records; cache may be added later as an optimization.
- Ban subject types are IP bucket, visitor ID, API key, combined anonymous subject, and optional authenticated user only for explicit compromise cases.
- Ban reasons use stable message/code catalogues.
- Ban responses use HTML or JSON according to request family and never expose raw signal internals.
- Suggested record fields are subject type/key, reason code, source signal digest, status, created at, expires at, lifted at, lifted by, lift reason, actor context hash, last matched at, match count, and audit reference.
- Initial TTL defaults should be conservative and test-backed: short anonymous/probe bans first, longer repeat bans only after repeated signals within the review window, and no permanent bans.
- Prefer Visitor-ID-backed bans for continuity. Add IP-bucket bans as a shorter secondary layer to reduce cookie-reset bypasses, and keep every IP-derived ban TTL below 30 days.
- Ban keys come only from the shared subject/client-identity resolver. Raw IP strings, raw API keys, and raw forwarding headers must never be stored as ban keys.
- Expiry and cleanup use an injectable clock/time boundary.

## Edge cases

- Expired bans must not block while cleanup is pending.
- Owner accounts must not be locked out by IP/visitor bans without an alternate documented recovery path.
- Shared IPs can be blocked only for clear anonymous abuse and should not permanently deny authenticated users.
- Invalid API keys may be banned by key fingerprint/prefix where safe, but raw submitted keys are never stored.
- IP-derived bans must expire and be cleaned up before the 30-day IP retention limit; expired IP bans must not remain searchable as historical Admin records with recoverable IP material.
- Manual unban must take effect immediately even if passive signals that created the ban still exist.
- Concurrent ban creation, expiry cleanup, and manual unban must be idempotent and auditable.
- Ban-store degradation must not create an invisible permanent block or lock out Owner recovery.

## Tests and validation

- Test active, expired, manually revoked, and cleanup states.
- Test anonymous enforcement and softer authenticated behavior.
- Test Owner recovery protection.
- Test HTML/JSON ban responses and redaction.
- Test Admin manual unban writes audit entries.
- Test repeat-ban TTL escalation stays bounded and does not create permanent bans.
- Test IP-derived ban TTL validation rejects or clamps values at 30 days and cleanup removes expired IP-derived records from review/export surfaces.
- Test trusted-proxy/client-identity behavior, ban-store degradation, and concurrent create/unban/cleanup behavior.
- Test migration applies on SQLite.

## Documentation and tracking

- Update Security draft with final subject types, statuses, and Owner protections.
- Update Admin/security diagnostics notes for review UI.
- Update class map for entity, repository, decision service, cleanup command/task, and Admin routes.
- Record threshold and false-positive assumptions in worklog.
- Complete the Security PR-readiness checklist from the master hardening plan before opening the PR.

## Non-goals

- No permanent invisible deny list.
- No GeoIP/country blocking.
- No machine-learning risk scoring.

## Acceptance criteria

- Clear bot/probe behavior can be blocked temporarily and reviewed.
- Operators can understand and reverse bans.
- Owner recovery remains available.
