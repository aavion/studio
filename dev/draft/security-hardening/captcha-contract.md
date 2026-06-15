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
- Rate reset hooks from `feat-security-rate-enforcement` where available.
- Existing Symfony Form, Validator, Translation, package contribution, and settings foundations.

## Implementation sequence

1. Define captcha provider and result contracts for render, validate, provider key, label key, and failure reason.
2. Add a resolver that selects a provider by workflow configuration and returns `none` behavior when disabled or unavailable.
3. Add workflow keys for first expected consumers: registration, contact, guest comments, and future login step-up.
4. Add a global captcha form field that delegates rendering/validation to the resolver.
5. Add validation mapping for recoverable failures and suspicious failures.
6. Add success/failure hooks to the abuse facade so success can reset scoped buckets and failure can record signals.

## Public interfaces and data decisions

- Provider key `none` always validates successfully.
- Missing or disabled provider validates successfully unless a future provider-required policy is explicitly configured for a workflow.
- Captcha result exposes only stable failure codes and safe context.
- Provider contracts are package-facing extension points and must be documented.

## Edge cases

- Captcha must not create anonymous sessions by default.
- Captcha validation never replaces CSRF, authentication, ACL, rate limiting, or domain validation.
- Provider render failures should degrade according to workflow policy and report safe diagnostics.
- Multi-language validation messages use deterministic translation keys.

## Tests and validation

- Test `none`, missing provider, disabled provider, success, recoverable failure, and suspicious failure.
- Test form integration does not break workflows with no provider.
- Test abuse hooks are called with safe context.
- Test provider registration rejects duplicate provider keys.
- Test translation catalogue synchronization for user-facing errors.

## Documentation and tracking

- Update Security and IconCaptcha drafts with final contract names.
- Update package developer guidance for provider registration.
- Update class map for provider interface, resolver, form type, and validation services.
- Record provider-required policy as deferred unless implemented.

## Non-goals

- No IconCaptcha assets, JavaScript, challenge generation, or refresh endpoint.
- No third-party captcha provider.

## Acceptance criteria

- Workflows can add captcha once and remain provider-agnostic.
- Future provider branches can implement only the contract without touching core forms.
