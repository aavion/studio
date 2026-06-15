# Captcha contract branch plan

> **Status**: Draft  
> **Updated**: 2026-06-15  
> **Owner**: Core  
> **Purpose:** Define the `feat-security-captcha-contract` implementation plan.  

## Goal

Add the generic captcha provider contract, resolver, workflow configuration, and global form integration without implementing IconCaptcha itself.

Back to [security hardening implementation plan](../0.2.x-SecurityHardeningPlan.md).

## Git handling

Codex may create local commits for this branch when each commit has a clear thematic scope. Pushes require explicit user instruction.

## Dependencies

- Abuse facade from `feat-security-abuse-foundation`.
- Rate reset hooks from `feat-security-rate-enforcement`.
- [Security policy defaults](policy-defaults.md).
- Existing Symfony Form, Validator, Translation, package contribution, and settings foundations.

## Legacy inspiration

The old Grav plugin `sec-lookup` at `/Volumes/Projekte/temp/sec-lookup` may be reviewed for captcha workflow expectations and human-recovery signals. Current provider-contract, graceful `none` behavior, Symfony Form integration, and package-facing extension rules have priority. Do not copy legacy logic or provider coupling directly.

## Implementation sequence

1. Define captcha provider and result contracts for render, validate, provider key, label key, and failure reason.
2. Add a resolver that selects a provider by workflow configuration and returns `none` behavior when disabled or unavailable.
3. Add workflow keys for first expected consumers: registration, contact, guest comments, and future login step-up.
4. Add a global captcha form field that delegates rendering/validation to the resolver.
5. Add validation mapping for recoverable failures and suspicious failures.
6. Add success/failure hooks to the abuse facade so verified provider success can reset scoped buckets and failure can record signals.

## Public interfaces and data decisions

- Provider key `none` always validates successfully.
- Missing or disabled provider validates successfully unless a future provider-required policy is explicitly configured for a workflow.
- Successful validation from `none`, a missing provider, or a disabled provider is graceful workflow success, not verified human success. It must not trigger rate-limit resets, ban relief, budget refill, or `429` recovery behavior.
- Captcha result exposes only stable failure codes and safe context.
- Captcha result must distinguish graceful unavailable-provider success from verified challenge success.
- Provider contracts are package-facing extension points and must be documented.
- Provider-required behavior is not enabled in the first contract branch; workflow policy may declare the shape for later enforcement, but default runtime behavior remains graceful success for unavailable providers.
- Provider selection and workflow mapping should be represented as audited configuration descriptors. Reset/recovery eligibility is not an ordinary setting: only verified provider-backed success may trigger scoped rate-limit reset or captcha-based recovery hooks.

## Edge cases

- Captcha must not create anonymous sessions by default.
- Captcha validation never replaces CSRF, authentication, ACL, rate limiting, or domain validation.
- Captcha-on-`429` recovery is available only when an active provider can render and validate a real challenge.
- Provider render failures should degrade according to workflow policy and report safe diagnostics.
- Multi-language validation messages use deterministic translation keys.

## Tests and validation

- Test `none`, missing provider, disabled provider, success, recoverable failure, and suspicious failure.
- Test `none`, missing provider, and disabled provider do not call reset/refill/recovery hooks.
- Test verified provider success is the only captcha result that may call scoped reset hooks.
- Test form integration does not break workflows with no provider.
- Test abuse hooks are called with safe context.
- Test provider registration rejects duplicate provider keys.
- Test configuration descriptor behavior for provider `none`, missing provider, disabled provider, and provider-required policy declarations where introduced.
- Test translation catalogue synchronization for user-facing errors.

## Documentation and tracking

- Update Security and IconCaptcha drafts with final contract names.
- Update Security policy defaults if provider-required workflow behavior or captcha success reset policy changes.
- Update package developer guidance for provider registration.
- Update class map for provider interface, resolver, form type, and validation services.
- Record provider-required policy as deferred design context; do not enforce it in this branch.
- Complete the Security PR-readiness checklist from the master hardening plan before opening the PR.

## Non-goals

- No IconCaptcha assets, JavaScript, challenge generation, or refresh endpoint.
- No third-party captcha provider.

## Acceptance criteria

- Workflows can add captcha once and remain provider-agnostic.
- Future provider branches can implement only the contract without touching core forms.
