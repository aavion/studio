# Remember-me branch plan

> **Status**: Draft  
> **Updated**: 2026-06-15  
> **Owner**: Core  
> **Purpose:** Define the `feat-security-remember-me` implementation plan.  

## Goal

Add persistent login using server-side revocable tokens that remain bound to account status, visitor identity, and audit policy.

Back to [security hardening implementation plan](../0.2.x-SecurityHardeningPlan.md).

## Git handling

Codex may create local commits for this branch when each commit has a clear thematic scope. Pushes require explicit user instruction.

## Dependencies

- Existing Symfony Security login, visitor identity/session binding, account lifecycle, audit logging, password change, logout, and APP_SECRET rotation handling.
- Symfony remember-me foundation.

## Implementation sequence

1. Add a server-side persistent login token model with selector, hashed token, owning user, visitor binding, issued/last-used timestamps, expiry, status, and revocation reason.
2. Configure Symfony remember-me to use an opaque browser selector/token and the server-side provider.
3. Add a login checkbox that issues a seven-day token only after explicit credential login.
4. On automatic login, validate token status, expiry, user status, visitor binding, and suspicious reuse before creating a fresh Symfony session.
5. Rotate token value on successful automatic login while preserving original expiry unless a full credential login issues a new token.
6. Revoke tokens on manual logout, password change/reset, account inactive/deleted status, security-review dispute, APP_SECRET emergency handling, and suspicious reuse.
7. Add audit entries for issue, auto-login success, logout revocation, mismatch, reuse, and lifecycle revocation.
8. Add a minimal profile/security UI for active persistent tokens with revoke-current, revoke-other, and revoke-all actions.

## Public interfaces and data decisions

- Trust window is seven days.
- Browser cookie contains only opaque selector/token material.
- Server stores only hashed token values.
- Remember-me never bypasses `UserAccountChecker`, account status, role checks, or session visitor binding.
- Token records include selector, hashed token, user, visitor binding hash, issued at, last used at, expires at, status, revocation reason, last IP bucket, last user-agent hash, and token family identifier.
- Token list/revocation UI is part of the branch scope so users can operate the feature without direct database access.

## Edge cases

- Copied remember-me cookie with different visitor signal is revoked and audited.
- Reused old rotated token revokes the token family where practical.
- Deleted/inactive users cannot auto-login.
- Owner accounts may use remember-me, but lifecycle revocation and recovery protection still apply.
- APP_SECRET rotation revokes active persistent tokens unless a tested re-encryption/rehash path exists.
- Revoking all other tokens must not destroy the current authenticated session unless the user explicitly revokes the current token/session.

## Tests and validation

- Test issue, auto-login, rotation, expiry, logout revocation, password/status revocation, and APP_SECRET revocation.
- Test visitor mismatch and token reuse audit.
- Test inactive/deleted user denial.
- Test cookie attributes and absence of raw token storage.
- Test profile token list and revoke actions, including current-token and other-token behavior.
- Test container/security firewall wiring.

## Documentation and tracking

- Update Security draft with final remember-me model.
- Update user account docs for the persistent-token review/revocation UI.
- Update class map for entity/provider/services/subscribers.
- Record verification around copied-cookie risk.

## Non-goals

- No bare identity cookie.
- No indefinite sliding session.
- No MFA/2FA step-up in this branch.

## Acceptance criteria

- Persistent login is revocable, auditable, visitor-bound, and bounded to seven days.
- Automatic login cannot revive disabled accounts or bypass existing security checks.
