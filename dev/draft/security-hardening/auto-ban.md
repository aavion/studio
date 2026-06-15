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

## Implementation sequence

1. Add database-backed ban records with subject type, normalized subject key, reason code, source signal summary, status, created/expiry timestamps, actor context where available, and manual unban metadata.
2. Add cleanup for expired bans through command and scheduler-ready task.
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

## Edge cases

- Expired bans must not block while cleanup is pending.
- Owner accounts must not be locked out by IP/visitor bans without an alternate documented recovery path.
- Shared IPs can be blocked only for clear anonymous abuse and should not permanently deny authenticated users.
- Invalid API keys may be banned by key fingerprint/prefix where safe, but raw submitted keys are never stored.

## Tests and validation

- Test active, expired, manually revoked, and cleanup states.
- Test anonymous enforcement and softer authenticated behavior.
- Test Owner recovery protection.
- Test HTML/JSON ban responses and redaction.
- Test Admin manual unban writes audit entries.
- Test migration applies on SQLite.

## Documentation and tracking

- Update Security draft with final subject types, statuses, and Owner protections.
- Update Admin/security diagnostics notes for review UI.
- Update class map for entity, repository, decision service, cleanup command/task, and Admin routes.
- Record threshold and false-positive assumptions in worklog.

## Non-goals

- No permanent invisible deny list.
- No GeoIP/country blocking.
- No machine-learning risk scoring.

## Acceptance criteria

- Clear bot/probe behavior can be blocked temporarily and reviewed.
- Operators can understand and reverse bans.
- Owner recovery remains available.
