# IconCaptcha branch plan

> **Status**: Draft  
> **Updated**: 2026-06-15  
> **Owner**: Core  
> **Purpose:** Define the `feat-security-icon-captcha` implementation plan.  

## Goal

Implement the first-party IconCaptcha provider as a replaceable package-owned provider behind the generic captcha contract.

Back to [security hardening implementation plan](../0.2.x-SecurityHardeningPlan.md).

## Git handling

Codex may create local commits for this branch when each commit has a clear thematic scope. Pushes require explicit user instruction.

## Dependencies

- `feat-security-captcha-contract`.
- Package lifecycle, AssetMapper/Tailwind, translation aggregation, `/api/live/**`, and abuse passive signal foundations.

## Legacy inspiration

The old Grav plugin `sec-lookup` at `/Volumes/Projekte/temp/sec-lookup` may be reviewed for IconCaptcha challenge flow, refresh behavior, accessibility pitfalls, and abuse-signal ideas. Current provider-owned package boundaries, deterministic one-shot challenge policy, cache/TTL decisions, `/api/live/**` behavior, and product decisions in this plan have priority. Do not copy legacy logic, assets, templates, secrets, identifiers, or framework-specific shortcuts directly.

## Implementation sequence

1. Add the first-party provider package skeleton with captcha-provider scope, package-owned services, templates, assets, translations, and JavaScript.
2. Select or create a suitable asset set before challenge implementation. Prefer open-source-compatible licenses such as MIT, Apache-2.0, CC0, or similarly permissive licenses, and record asset provenance/license notes in the provider package.
3. Implement deterministic challenge generation from provider secret, challenge ID, timestamp, workflow key, route context, user agent, and optional existing session/visitor signal.
4. Store one-shot challenge IDs and short-lived challenge metadata in a dedicated Symfony cache pool where practical, falling back to `cache.app` if the project has no dedicated pool yet.
5. Implement validation for missing, expired, reused, invalid choice, wrong choice, context mismatch, asset error, and provider unavailable.
6. Add lightweight refresh through `/api/live/**` or a provider-owned JSON route with no ordinary rate-limit rejection; record passive abuse signals for aggressive refreshes.
7. Add accessible, layout-stable UI with fixed button grid, translated labels, keyboard support, and back-forward-cache refresh handling.

## Public interfaces and data decisions

- Provider key is `icon_captcha`.
- Public challenge payload contains only challenge ID, timestamp, render metadata, and button identifiers needed for display.
- Provider secret is generated/configured outside manifests and public assets.
- Default challenge TTL is five minutes, and validation invalidates the challenge after every attempt, successful or failed.
- SVG/icons must be allowlisted or sanitized before inline rendering.
- Inline-rendered graphics and symbol SVGs must not expose answer-bearing names through file names, element IDs, CSS classes, `data-*` attributes, titles, descriptions, or translation keys. Use opaque challenge-local identifiers and randomized or non-semantic button identifiers.
- Accessibility labels must describe the control purpose without revealing the visual answer. Prefer neutral labels such as option numbers and state/status text over labels that name the target icon or symbol. If this makes the visual challenge insufficient for assistive technology, document and implement a separate accessible fallback flow instead of leaking the answer through ARIA.

## Edge cases

- Challenge reuse fails after validation regardless of success or failure.
- Expired challenges fail recoverably.
- Context mismatch is suspicious but should not reveal internals.
- Disabled provider falls back according to the generic resolver policy.
- Asset loading failures produce safe diagnostics and recoverable user feedback where possible.
- Asset license gaps or unclear provenance block the provider branch until the asset is replaced or the license is documented as acceptable.
- Browser inspection should not reveal the correct answer through DOM order, source file names, SVG IDs, ARIA labels, visible hidden text, or static asset URLs.

## Tests and validation

- Test challenge generation determinism and answer validation.
- Test every failure model.
- Test one-shot replay prevention and TTL expiry.
- Test cache-pool fallback and secret absence from cached/public challenge payloads.
- Test refresh no-store behavior and passive signal recording.
- Test package asset/template/translation registration.
- Test keyboard/accessibility behavior where practical with JS tests.
- Test that rendered DOM, inline SVG, ARIA labels, asset paths, and serialized challenge payloads do not expose answer-bearing names or reusable answer material.
- Verify asset licenses/provenance and record the result in branch documentation or package metadata.

## Documentation and tracking

- Update IconCaptcha draft with final payload/storage choices.
- Update package developer guidance if provider package layout adds a reusable pattern.
- Update class map for provider, challenge services, controller/live endpoint, assets, and templates.
- Record asset licensing, provenance, sanitization, and bot-resistance notes.
- Complete the Security PR-readiness checklist from the master hardening plan before opening the PR.

## Non-goals

- No workflow-specific IconCaptcha code in contact, registration, or password forms.
- No copied legacy implementation, secrets, or hard-coded old asset paths.
- No answer-bearing asset, DOM, CSS, JavaScript, translation, or ARIA names that make the challenge solvable by simple static heuristics.

## Acceptance criteria

- IconCaptcha can be enabled, disabled, or replaced without changing workflow forms.
- Challenge payloads and logs do not expose secrets or reusable answer material.
