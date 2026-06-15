# Mailer account delivery branch plan

> **Status**: Draft  
> **Updated**: 2026-06-15  
> **Owner**: Core  
> **Purpose:** Define the `feat-security-mailer-account-delivery` implementation plan.  

## Goal

Replace production reliance on message-log action URLs with real Symfony Mailer/Messenger delivery for account and recovery flows.

Back to [security hardening implementation plan](../0.2.x-SecurityHardeningPlan.md).

## Git handling

Codex may create local commits for this branch when each commit has a clear thematic scope. Pushes require explicit user instruction.

## Dependencies

- Existing account-link delivery boundary, mail-flow registry, locale resolver, message logging, Messenger, and account-token flows.
- Security settings/admin settings foundation.

## Implementation sequence

1. Replace the hard-coded mail-flow registry with provider-backed registration while preserving current built-in account flow keys.
2. Add localized Markdown template storage/editing for built-in account flows.
3. Render Markdown to HTML and plain text with placeholder replacement from allowed parameter keys only.
4. Queue mail delivery through Messenger with a conservative transport guard and safe failure reporting.
5. Keep message-log action-link delivery as an explicit debug aid only, gated by debug configuration and strong Admin warning.
6. Update account, registration, password reset, security review, closure, restore, and APP_SECRET recovery flows to use the real delivery service in production.

## Public interfaces and data decisions

- Mail flow/template keys remain stable machine identifiers.
- Allowed replacement keys are defined by the mail-flow registry/provider metadata.
- Clear action URLs may exist in queued mail payloads but must not be duplicated into log context.
- Missing localized templates fall back to default language and record safe warnings.
- The first transport guard allows one queued account-flow message per user action, requires configured sender/transport before production delivery, and relies on Messenger retry/backoff instead of controller-level loops.
- Built-in account flows must remain registered by provider metadata even if no third-party provider exists yet.

## Edge cases

- Mail transport unavailable should produce recoverable UI/message feedback without exposing SMTP internals.
- Queue failures should not leave account tokens silently unreachable; callers receive a generic delivery failure.
- Debug log delivery must be unavailable or strongly warned in production-like environments.
- APP_SECRET recovery owner links need safe partial-failure reporting.
- Duplicate delivery attempts for the same token/action should be idempotent or clearly audited so operators can distinguish retries from new security events.

## Tests and validation

- Test registry provider completeness and duplicate key rejection.
- Test template fallback, placeholder replacement, HTML/plain rendering, and unsupported placeholders.
- Test Messenger queue payload redaction.
- Test account flows use real delivery when configured and debug delivery only when allowed.
- Test transport guard behavior and safe failure messages.
- Test duplicate/retry behavior for token-bearing account messages.

## Documentation and tracking

- Update Mailer Delivery Contract with final provider and template behavior.
- Update Security draft where the debug stub is replaced/gated.
- Update class map for registry providers, renderer, queued message/handler, and settings UI.
- Update user/admin docs if Mail settings become visible.
- Complete the Security PR-readiness checklist from the master hardening plan before opening the PR.

## Non-goals

- No newsletter, CRM, or campaign tooling.
- No third-party transactional mail provider package.

## Acceptance criteria

- Production account operations no longer require reading action URLs from message logs.
- Mail failures are visible, recoverable, and redacted.
