# Captcha contract branch plan

> **Status**: Draft
> **Updated**: 2026-06-21
> **Owner**: Core
> **Purpose:** Define the `feat-security-captcha-contract` implementation plan.

## Goal

Build the generic extension runtime contract work needed before captcha can be implemented as a self-contained provider extension, then add the provider-agnostic captcha form contract without implementing IconCaptcha itself or adding provider-specific frontend assets to core.

Back to [security hardening implementation plan](../0.2.x-SecurityHardeningPlan.md).

## Git handling

Codex may create local commits for this branch when each commit has a clear thematic scope. Pushes require explicit user instruction.

## Product decisions

- Extensions are trusted administrator-installed code, but they are not Symfony bundles and are not auto-registered as services.
- `EXTENSION_NAMESPACE` only enables PSR-4 class loading for active, valid extensions below the declared namespace and child namespaces. It does not imply container service discovery.
- Extension-local dependencies are extension-owned. Studio must not automatically register `extensions/<slug>/vendor/autoload.php`.
- No automatic service discovery does not prevent extensions from instantiating and using their own extension-owned service objects internally.
- No automatic vendor autoload registration does not prevent extensions from manually bootstrapping extension-owned dependencies when the PHP policy boundary supports it.
- `extension.php` remains the explicit contribution entry point.
- Extension contributions may be static values or explicit lazy contribution factories so extensions can generate dynamic contributions from extension-owned variables, classes, and callables.
- Lazy callables must be wrapped in typed contribution objects with a clear phase and contract. Avoid accepting ambiguous naked callables whose intent cannot be determined.
- Runtime boot and activation/install routines are separate contribution phases.
- Provider selection is lifecycle-owned. For the first implementation, each single-active provider scope, including `captcha-provider`, has at most one active real extension. The active extension is the selected provider.
- Remove the active captcha provider setting (`security.captcha.provider`) from the contract plan because it duplicates extension activation state. Later Admin extension views may add provider-scope filters and activation shortcuts instead.
- Provider-specific options, variants, challenge behavior, external credentials, and UI choices belong to the provider extension's own settings and code.
- Public extension events must stay curated. Extensions should use the contribution-facing event-listener API, but core must expose only documented public hook events through the hook registry.

## Dependencies

- Existing extension lifecycle, scope, activation, validation, runtime contribution, asset, template, API/live endpoint, scheduler, settings, database, and content-schema foundations.
- Existing `PublicEventHookRegistry` and domain-owned `EventHookDescriptorProviderInterface` model.
- Abuse facade from `feat-security-abuse-foundation`.
- Rate reset hooks from `feat-security-rate-enforcement`.
- [Security policy defaults](policy-defaults.md).
- Existing Symfony Form, Validator, Translation, and generic form field foundations.

## Current code surfaces to touch

- `ExtensionContributions`: add typed lazy contribution APIs, boot/init APIs, provider APIs, and event listener APIs.
- `ExtensionPhpLoader`: load active runtime contributions, register runtime boot callables, and execute runtime boot at request/runtime phase only.
- `ExtensionContributionReader`: read activation/install contributions without triggering request/runtime boot side effects.
- `ExtensionRuntimeContributionRegistry`: keep ownership-attributed staged registration, add dynamic contribution expansion, provider callables, and extension event listener storage.
- `ExtensionRuntimeContributionExpander`: expand static providers, lazy contribution factories, provider callables, and event listener definitions.
- `ExtensionRuntimeContributionGuard`: enforce owner, scope, template namespace, endpoint path, handler key, event eligibility, and provider-scope rules centrally.
- `ActiveExtensionProvider` and provider-scope lifecycle code: keep single-active provider behavior authoritative.
- `ExtensionValidator` and adjacent validators: keep static validation for namespace, syntax, template scope, PHP policy, CSS namespace, and obvious disallowed capability use.
- `PublicEventDispatcher` / `PublicEventHookRegistry`: adapt callable listener contributions to documented public events while preserving structured failure handling.
- `CoreSettingsRegistry` and Admin settings rendering: remove the active captcha provider setting, keep only workflow/policy settings that are not provider selection.
- `templates/provider/captcha/field.html.twig` and form rendering: convert placeholder behavior into the provider-agnostic fallback contract.

## Known risks and guardrails

This branch deliberately increases extension flexibility. Each slice must therefore protect the core boundary without trying to sandbox trusted administrator-installed PHP code.

- **Phase confusion:** runtime boot, runtime contribution factories, activation/install factories, provider processors, event listeners, and endpoint handlers are all callable-based but must never share one ambiguous callable path.
- **Double execution:** `extension.php` is already read during activation contribution application and runtime loading. Boot and runtime factories must not accidentally execute during activation-only reads.
- **Partial registration:** dynamic factories and boot failures can leave half-registered routes, handlers, templates, listeners, or provider callables unless registry mutation remains staged.
- **Ownership drift:** every contributed object or callable must stay attributable to the active extension that registered it for diagnostics, faulting, and dependency deactivation.
- **Scope drift:** provider, API, live endpoint, database, content-schema, scheduler, cookie, event, and view contributions must remain gated by their owning scopes or documented public surfaces.
- **Template namespace collision:** provider Twig lookup may use the shared `@provider` namespace, but validated provider templates must stay below `templates/provider/{provider-scope}/**`. A provider extension with a different scope must not be able to publish or shadow `@provider/captcha/**`, and inactive providers must not be registered as Twig paths.
- **Request-data bypass:** extensions need request, query, and form data for their own forms and endpoints, but that data should arrive through the matching contribution context or handler request object. Ambient superglobals let extension code inspect unrelated core forms, cookies, sessions, CSRF-adjacent values, and request metadata outside the reviewed contract.
- **Recovery bypass:** skipped captcha, missing providers, disabled providers, or provider faults must not become verified human proof, reset rate limits, clear bans, or unlock `429` recovery.
- **Fault overreach:** ordinary provider runtime failures should not fault an extension immediately unless the failure violates a contract invariant; otherwise one bad challenge request could disable a whole provider.
- **Silent degradation:** catchable provider failures should still create safe diagnostics and user-visible validation failure where the workflow requires captcha.
- **Container creep:** adding context objects or adapters must not become indirect Symfony container access for extensions.
- **Review scope creep:** do not refactor existing API/live/scheduler/database contribution contracts unless the new runtime model exposes a concrete boundary mismatch.

## Coding-agent guardrails

Implementation agents should treat this plan as a staged contract migration, not as permission to rewrite the extension system broadly.

- Treat every code change as conditional on a fresh plausibility check against the current codebase. This draft is guidance, not an instruction to force an implementation that no longer fits the code.
- Stop and ask for product input when an implementation choice cannot be derived from explicit product decisions, existing architecture, security policy, or reversible pre-`1.0.0` cleanup rules.
- Do not silently decide open product questions while coding, especially around extension trust level, request-data access, provider-required behavior, failure blocking, public event exposure, filesystem/network access, or lifecycle selection semantics.
- Work one implementation slice at a time and keep each commit thematic. Do not mix captcha form wiring with generic extension runtime, validator, or event-dispatch refactors.
- Before changing a boundary, trace source, control point, sink, rollback/fault path, and sibling contribution types. Prefer one central guard over repeated path-local checks.
- Preserve the existing "no Symfony bundle" rule: no automatic service discovery, no container injection into extension contexts, and no automatic extension-local Composer vendor autoload registration.
- Replace ambiguous callable handling atomically. A code path that accepts a callable must know whether it is activation-time, runtime-time, boot-time, provider-time, listener-time, endpoint-time, or scheduler-time.
- Stage dynamic contribution expansion before committing to runtime registries. A failed factory, boot, provider registration, or listener registration must not leave partially visible routes, handlers, listeners, templates, settings, scheduler tasks, or providers behind.
- Keep extension ownership attached to every contribution, callable, diagnostic, fault decision, and future cleanup action.
- Keep provider scope checks independent from provider implementation details. Core may know `captcha-provider`; it must not know IconCaptcha, ReCaptcha, their asset names, challenge payloads, CSS classes, JavaScript controllers, or polling schemas.
- Use context DTOs and narrow facades for documented data exchange. Do not pass the service container to extension code, and do not add broad raw-request access where a field payload, query bag, endpoint request, or event-specific context is enough.
- When loosening a validator rule to support self-contained extensions, add the matching negative tests in the same slice: foreign owner, inactive extension, wrong scope, path traversal, stale contribution, duplicate identifier, malformed callable, and public-error redaction where applicable.
- Keep user-facing behavior translated and documented. Update developer docs, drafts, worklog, class map, translation catalogues, and tests in the slice that changes the public contract.
- Do not keep compatibility shims for removed pre-`1.0.0` behavior unless explicitly requested. Remove `security.captcha.provider` cleanly from settings, tests, translations, docs, and UI assumptions.
- Run a focused local review pass before each commit: look for bypasses, fallback semantics, stale config, missing cleanup, partial state, and whether a future Cloud Review agent could reach a different source-to-sink path.

## Blacklist policy

Extension validation should stay boundary-oriented and blacklist only capabilities that threaten host integrity, core-owned namespaces, or public/runtime isolation. It must not block ordinary extension-owned PHP logic merely because it is powerful.

Keep blocking:

- Direct process and shell execution: `exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open`, and string/dynamic callable forms that resolve to those functions.
- Dynamic code execution and opaque callable dispatch: `eval`, broad `call_user_func*`, `forward_static_call*`, variable function calls, callable expression calls, and string-literal callables that bypass function-name scanning.
- Ambient request and environment superglobals in general extension PHP: `$_GET`, `$_POST`, `$_COOKIE`, `$_REQUEST`, `$_SESSION`, `$_FILES`, `$_SERVER`, and `$_ENV`. Extensions may still receive request/query/form/file data through documented API/live/form/event/captcha context objects or handler method parameters.
- Direct environment mutation or reads that bypass context/config contracts: `getenv`, `putenv`, and environment superglobals.
- Namespace spoofing: classes below `src/` outside `EXTENSION_NAMESPACE` and child namespaces.
- Public boundary escapes: cross-scope Twig references, foreign provider templates, foreign API/live handler keys, foreign route paths, foreign scheduler identifiers/targets, foreign settings, and foreign cookie names.
- Public asset escapes: blocked executable or unsafe files under mirrored assets, CSS selectors that target another owner as their subject, and inactive extension assets becoming public.
- Raw schema/DDL escapes: DBAL `columnDefinition`-style raw options, vendor-specific DDL, cross-extension foreign keys, or direct migration execution outside extension database contracts.
- Unsafe cookie behavior: reserved core cookie names, cross-site necessary cookies, and unreviewed core technical-cookie reuse.

Allow by design:

- Extension-owned PHP classes, functions, constants, private files, caches, internal includes, and local dependencies when the extension owns the bootstrap risk.
- Extension-owned HTTP/API/live handlers through documented endpoint contributions.
- Extension-owned filesystem reads for private extension assets and state where they do not publish or modify core-owned paths.
- External HTTP calls by provider/runtime code when the owning contract needs them, for example ReCaptcha verification, with safe diagnostics and timeout expectations documented by that provider.
- Symfony and Studio class imports for public interfaces and facades exposed by documented contracts.

The current static PHP policy still blocks language-level includes and many direct filesystem or network functions. The implementation must intentionally reconcile that policy with the self-contained extension goal before captcha providers depend on private PHP files, private assets, local caches, or external verification calls. Because a token scanner cannot reliably prove every native filesystem or network target is extension-owned, prefer documented context helpers or narrow facades where the core needs enforceable ownership checks. If native includes or direct local file reads are allowed, cover absolute paths, `..` traversal, symlink-like escapes where practical, inactive extensions, and public asset/template boundary escapes with tests.

If a future review finding requires tightening this policy, prefer a narrow blacklist or contribution guard at the central boundary over a broad whitelist that would make creative extensions impossible.

## Contract model

### Extension contribution phases

Define explicit contribution wrappers so callables cannot be misclassified:

- `ExtensionRuntimeContributionFactory`: called during active runtime loading to produce ordinary runtime contributions.
- `ExtensionActivationContributionFactory`: called during activation/install contribution application to produce activation-only contributions such as extension database tables or content schema presets.
- `ExtensionRuntimeBoot`: called once for an active extension in the runtime loader after contributions are registered, for extension-owned initialization that must be available during the current request/runtime.
- `ExtensionEventListenerContribution`: registers a callable for one documented public event class or public hook alias.
- `{scope}ProviderContribution`: registers the callable used by a provider-scope contract, for example `CaptchaProviderContribution`.

The contribution builder should remain ergonomic:

```php
return ExtensionContributions::create()
    ->runtime(static fn (ExtensionContributionContext $context): iterable => [
        // Dynamic runtime contributions.
    ])
    ->activation(static fn (ExtensionActivationContext $context): iterable => [
        // Activation/install-only contributions.
    ])
    ->runtimeBoot(static function (ExtensionRuntimeContext $context): void {
        // Extension-owned runtime setup.
    })
    ->eventListener(
        App\View\ViewContextEvent::class,
        static fn (App\View\ViewContextEvent $event, ExtensionEventContext $context): void => null,
    )
    ->captchaProvider(static fn (CaptchaValidationRequest $request): CaptchaValidationResult => CaptchaValidationResult::skipped());
```

### Context objects

Use small, documented context DTOs instead of passing the container:

- `ExtensionContext`: extension slug, path helpers, manifest metadata, environment, and safe diagnostics helpers.
- `ExtensionContributionContext`: shared contribution-generation context.
- `ExtensionActivationContext`: activation/install-only context with operation diagnostics and activation metadata.
- `ExtensionRuntimeContext`: runtime/request loader context with no service-container access.
- `ExtensionEventContext`: event listener context with extension identity and structured failure helpers.
- `CaptchaRenderContext` and `CaptchaValidationRequest`: captcha-specific workflow, form instance, submitted payload, route/request metadata, and safe abuse/rate context.

Context DTOs may expose narrow core facades later when a contract needs them. Avoid giving extensions the Symfony container.

Do not use context DTOs as a hidden container substitute. Each exposed method should answer one documented extension need, have stable ownership semantics, and be reviewable as a data boundary. If an extension needs request data, expose only the relevant route/query/form/file/cookie-policy data for the active contribution or handler surface rather than ambient access to unrelated request state.

## Event listener contract

Add an extension-facing event-listener contribution API instead of auto-wiring extension subscribers.

- Extensions register `eventListener(public-event, callable, priority = 0)`.
- The registry accepts only events known to `PublicEventHookRegistry`.
- Domain teams add new extension events through their existing domain-owned `EventHookDescriptorProviderInterface`; the registry remains the aggregated listing and documentation source, not a monolithic hardcoded event list.
- Use public event class names as canonical identifiers. Optional readable aliases may be derived from descriptors for documentation and UI, but class names remain stable dispatch keys.
- Listener callables receive the typed event and an `ExtensionEventContext`.
- Listener failures are converted through `PublicEventDispatcher` into structured diagnostics.
- Runtime listener failures should block only when the event contract says continuing is unsafe. Otherwise they should be logged and surfaced in diagnostics.
- Mark an extension `faulty` only for true invariants, invalid contribution registration, or repeated/severe runtime failures once that policy is implemented.

Implementation must add an explicit adapter between stored extension listener contributions and `PublicEventDispatcher`. Symfony's EventDispatcher remains the native dispatch mechanism, but extension listener callables are not Symfony services. The adapter must define ordering relative to native listeners, priority sorting, duplicate handling, ownership attribution, and failure conversion in one place.

### Initial event backlog for this branch

Curate and verify the first extension-facing event list rather than trying to expose every Symfony or core event. The adapter may source events from existing `PublicEventInterface` events, Symfony HttpKernel events, or future domain events, but extension-facing names should stay stable, lowercase, dot-separated, and easy to document.

Do not expose raw Symfony event names such as `kernel.request` as the extension contract. Use them only as adapter implementation details. Extension-facing names should describe the product moment, not the framework event class.

Initial event naming pattern:

- `http.*` for request/response lifecycle moments.
- `view.*` for Twig/page context and rendered output moments.
- `content.*` for content entity rendering moments.
- `navigation.*` for navigation/menu composition.
- `form.*` for future generic form assembly/submission moments.
- `extension.*` for extension lifecycle and asset/runtime registry moments.
- `{scope}.*` for future provider-specific lifecycle moments such as `captcha.challenge.*`.

Initial enabled events:

- `view.context`: add global Twig context values. Source: current `ViewContextEvent`. Mode: extend.
- `content.render_context`: add per-content Twig variables. Source: current `ContentRenderContextEvent`. Mode: extend.
- `content.rendered`: post-process one rendered content fragment. Source: current `ContentRenderedEvent`. Mode: extend.
- `navigation.build`: add or reorder navigation items. Source: current `NavigationBuilderEvent`. Mode: extend.
- `http.response.headers`: add safe non-security-sensitive headers. Source: current `ResponseHeadersEvent`, potentially backed by `kernel.response`. Mode: extend.
- `view.output_generated`: final HTML-only output adjustment. Source: current `OutputGeneratedEvent`. Mode: extend.
- `view.static_injections`: contribute route/menu view injections through definitions. Source: current `StaticViewInjectionRegistryEvent`. Mode: extend.
- `view.dynamic_injections`: contribute content-aware slot injections through definitions. Source: current `DynamicViewInjectionRegistryEvent`. Mode: extend.
- `extension.assets.build`: add extension-owned asset registry entries. Source: current `ExtensionAssetRegistryBuildEvent`. Mode: extend.

Initial Symfony-sourced adapter candidates:

- `http.request.matched`: observe the resolved route/request context after routing has populated request attributes and before controller execution. Source candidate: `kernel.request`, only if adapter priority can guarantee route attributes are available without bypassing setup/security guards. Mode: observe first; extend only after a concrete use case.
- `http.controller.ready`: observe the selected controller and normalized route metadata before invocation. Source candidate: `kernel.controller`. Mode: observe.
- `http.controller.arguments`: observe or narrowly extend documented controller argument metadata. Source candidate: `kernel.controller_arguments`. Mode: defer unless a real extension use case needs it because argument mutation can become a bypass surface.
- `http.response.ready`: observe the response before it is sent. Source candidate: `kernel.response`. Mode: observe; header mutation should prefer `http.response.headers`.
- `http.request.finished`: request-scope cleanup after response generation for the current request stack frame. Source candidate: `kernel.finish_request`. Mode: observe.
- `http.terminated`: post-response cleanup or async handoff after response send. Source candidate: `kernel.terminate`. Mode: observe only; no user-visible workflow decisions.

Deferred unless a concrete implementation needs them:

- Form assembly hooks.
- Form submission hooks.
- Captcha-specific challenge lifecycle events.
- API response context hooks.
- Scheduler task collection events.
- Media resolution events.
- Symfony `kernel.view` result replacement, `kernel.exception`, security authentication/authorization events, Messenger worker events, and low-level Doctrine events.

Before enabling a Symfony-sourced adapter event, confirm:

- The adapter can present a small stable DTO instead of the raw Symfony event when raw access would expose too much request, controller, security, session, or response state.
- The event has a clear mutability mode: observe, extend, transform, or veto. Start with observe when unsure.
- Listener failures have a documented effect on the request. Post-response events must not retroactively change workflow success.
- The event does not allow extensions to bypass authentication, authorization, CSRF, rate limiting, captcha, setup locks, maintenance mode, or response redaction.
- The adapter priority is documented relative to core subscribers such as setup redirects, locale handling, API security, template path configuration, and response/header processing.

## Provider contribution contract

Provider scopes follow one repeatable pattern:

- A provider scope is named `{scope}-provider`.
- The corresponding core contract is named `{Scope}ProviderInterface` or `{Scope}ProviderContribution`, depending on whether the runtime needs an object contract or one callable.
- The provider contribution is accepted only from an active extension with the matching provider scope.
- Provider scopes are single-active in the first implementation. Activating one real provider extension deactivates the conflicting real provider extension for the same scope.
- The active provider is resolved from extension lifecycle state. No separate active-provider config key is needed.
- The `system`/native fallback remains the graceful no-provider behavior and must not be deactivated by real provider activation.
- Provider templates use deterministic provider subdirectories, for example `@provider/captcha/**`. The shared Twig namespace is acceptable when validation enforces `templates/provider/{scope}/**` for the owning provider scope and only active extensions are registered as Twig paths.
- Provider live endpoints, API endpoints, cookies, assets, settings, cache use, external HTTP calls, and validation internals are provider-owned and guarded by existing extension contribution rules.

Do not add multi-active provider selection in this branch. Multiple installed providers can exist as inactive extensions; choosing one means activating it.

## Captcha contract

Add the generic captcha provider data-exchange contract, workflow configuration, global form integration, and result handling without implementing IconCaptcha.

### Captcha runtime contracts

Define:

- `CaptchaProviderContribution`: callable/provider contribution accepted only from an active `captcha-provider` extension.
- `CaptchaProviderBridge`: core-owned bridge that resolves the active `captcha-provider` contribution or native fallback.
- `CaptchaRenderContext`: workflow key, form instance ID, field name, route, locale, and safe public metadata.
- `CaptchaValidationRequest`: workflow key, form instance ID, submitted payload, route/request metadata, and safe abuse/rate context.
- `CaptchaValidationResult`: explicit result kind: `skipped`, `verified`, `recoverableFailure`, `suspiciousFailure`, `providerUnavailable`, and `providerFault`.
- `CaptchaFailureCode`: stable failure codes suitable for translation, logging, and abuse signals.

The provider callable should own challenge generation, challenge refresh, one-shot validation, external verification, and provider-specific payload interpretation.

### Form integration

- Keep a global captcha form field/Twig partial that any workflow can render.
- Rendering uses `@provider/captcha/field.html.twig`.
- If no real captcha provider is active, native fallback renders no visible challenge and submits only enough hidden/non-display state to mark graceful skipped validation.
- Every rendered captcha instance receives a stable unique form/captcha instance ID so multiple forms on one page are addressable.
- Submitted captcha payload is passed to the active provider before ordinary form validation continues.
- Skipped/no-provider success lets the workflow continue but is not verified human proof.
- Verified provider success may reset only explicitly resettable captcha-failure buckets where policy allows.
- Provider `none`, missing provider, disabled provider, skipped result, or fallback success must not reset rate limits, refill budgets, clear bans, or satisfy captcha-based `429` recovery.

### Settings and workflow configuration

- Remove `security.captcha.provider` from the active provider contract. Provider activation selects the provider.
- Keep or add only workflow/policy settings, such as captcha enabled for a workflow, provider-required policy once designed, and owner-controlled recovery behavior.
- Provider-specific settings live under the provider extension's own extension settings.
- Admin extension views may later offer filtered provider-scope activation UX, but this is not the same as a core provider setting.

### Live endpoint and assets

- Captcha providers may expose extension-owned `/api/live/{extension-slug}/...` endpoints for refresh or polling through existing live endpoint contributions.
- Core must not ship provider-specific JavaScript controllers, CSS classes, challenge generators, polling endpoints, or payload schemas.
- Live endpoint abuse must follow existing `/api/live/**` policy: no ordinary rate-limit rejection, but aggressive or suspicious behavior may feed passive signals or provider-owned failure results.

## Implementation slices

1. Document final runtime policy and align drafts.

   **Review checkpoint:** Search for old resolver/provider-setting language, accidental service-autowiring implications, and contradictions between Security, PluginModules, EventHooks, IconCaptcha, worklog, and class map. Confirm the plan does not promise filesystem, HTTP, cache, or request access that the validator still blocks without an intentional replacement boundary.

2. Add typed contribution wrappers for runtime factories, activation factories, runtime boot, event listeners, and captcha provider contributions.

   **Review checkpoint:** Verify every callable wrapper has one phase, one input context, one expected return shape, and one failure policy. Search for any code path accepting ambiguous naked callables, including legacy `extension.php` return values in activation and runtime loaders.

3. Split `extension.php` loading by phase so activation/install contribution reading cannot accidentally execute runtime boot.

   **Review checkpoint:** Trace both `ExtensionPhpLoader` and `ExtensionContributionReader` from source to sink. Add tests proving activation reads activation contributions only, runtime reads runtime contributions and boot, naked callables are rejected or treated by one documented legacy rule only, and neither path leaves partial state after failure.

4. Add PSR-4 active namespace loader if not already implemented, with tests for active-only loading, child namespaces, inactive/faulty/removed exclusion, and no automatic vendor autoload.

   **Review checkpoint:** Test namespace collision, inactive provider class loading, faulty extension class loading, removed extension class loading, missing `EXTENSION_NAMESPACE`, invalid namespace, and extension-local `vendor/autoload.php` non-registration.

5. Extend runtime contribution expansion and guarding for lazy factories and provider contributions.

   **Review checkpoint:** Inspect every existing contribution type for sibling bypasses: API definitions and handlers, live definitions and handlers, scheduler tasks and callable providers, view injections, cookies, database tables, content schemas, and settings. Prefer central guard changes over path-local checks.

6. Add extension event listener contribution support backed by `PublicEventHookRegistry`.

   **Review checkpoint:** Attempt to register internal Symfony events, unknown events, duplicate listener identifiers, wrong callable signatures, foreign-owner listeners, and listener exceptions. Confirm the dispatch adapter invokes extension listeners exactly once, in documented order, and preserves extension ownership in diagnostics.

7. Curate and test the initial public event list for extension listener contributions.

   **Review checkpoint:** For each event, classify mode, mutability, stoppability, sensitive data exposure, failure behavior, and whether continuing after listener failure can leak data, skip validation, corrupt output, or weaken headers.

8. Add captcha provider bridge, request/context/result DTOs, native fallback provider behavior, and failure code catalogue.

   **Review checkpoint:** Verify provider callables receive only documented payload/context, not raw request internals or secrets. Test skipped/fallback/unavailable/fault results against reset/recovery paths. Confirm the bridge can support external verification providers without adding provider-specific code to core.

9. Replace `security.captcha.provider` usage with active `captcha-provider` lifecycle resolution.

   **Review checkpoint:** Search settings, translations, admin forms, tests, defaults, docs, and drafts for stale provider-selection state. Confirm activation/deactivation is the only provider selection authority.

10. Wire the global captcha field render and submit pipeline to the captcha bridge.

   **Review checkpoint:** Test multiple forms per page, missing provider, inactive provider, active provider render failure, active provider validation failure, malformed payloads, replay-like payloads, and public form behavior without creating anonymous sessions. Confirm captcha validation runs before ordinary form submission side effects and cannot be bypassed by omitting the provider field.

11. Add verified-success and failure integration with rate-limit reset and abuse signal boundaries.

   **Review checkpoint:** Prove only verified provider-backed success can reset the captcha-failure bucket, only for intended subjects, and skipped/faulty/unavailable provider results cannot reset, refill, clear bans, or satisfy recovery.

12. Update tests, docs, class map, worklog, and Security/IconCaptcha drafts.

   **Review checkpoint:** Run a final local review pass over the changed diff focused on bypasses, adjacent contribution surfaces, public/request boundaries, fallback behavior, stale docs, and missing regression tests before committing the final slice.

## Review-hardening checklist

At every checkpoint and again before opening review, verify these edges explicitly:

- Runtime boot never runs during activation-only contribution reads unless a contribution declares that phase.
- Lazy contribution factories are staged before commit to the runtime registry; failed factories leave no partial contributions behind.
- Provider contribution callables are accepted only for matching active provider scopes.
- Provider scopes remain single-active and activation conflicts are planned before mutation.
- Native/system fallback records cannot be deactivated by single-active provider replacement.
- Template lookup for `@provider/captcha/**` resolves only an active `captcha-provider` extension or native fallback. The validator must reject `templates/provider/captcha/**` from non-`captcha-provider` extensions.
- Extension event listeners can target only public hook registry entries.
- Listener ownership is preserved for diagnostics and future faulting.
- Extension listener dispatch order, priority, duplicate handling, and failure conversion are centrally defined and covered by tests.
- Runtime provider/handler exceptions do not leak raw errors to public users.
- True invariant failures mark the owning extension `faulty`; ordinary catchable provider failures become structured result failures.
- Removed `security.captcha.provider` state cannot leave stale settings, UI choices, translation labels, tests, or docs behind.
- Captcha skipped/fallback success cannot reset rate limits or satisfy recovery.
- Captcha verified success cannot reset unrelated buckets or unbounded subjects.
- Captcha payloads, challenge IDs, provider internals, submitted answers, raw IPs, session IDs, or secrets are not logged.
- Ambient request superglobals remain blocked in general extension PHP; documented request, query, form, file, and cookie-policy data reaches extension-owned providers, forms, endpoints, and hooks through context DTOs or handler method parameters.
- Direct process/shell execution remains blocked unless a future `operations` scope routes it through the Operation layer.
- Extension-owned filesystem reads remain possible for extension-private files without exposing public asset or template boundary escapes.

## Tests and validation

- Unit-test contribution wrapper expansion, lazy factory execution, staging rollback, and unsupported contribution diagnostics.
- Test runtime and activation phases independently.
- Test runtime boot once-per-runtime-loader behavior and idempotency expectations.
- Test active namespace autoloading and inactive/faulty/removed exclusions.
- Test extension-local `vendor/autoload.php` is not registered automatically.
- Test event listener contributions against allowed and disallowed events.
- Test listener priority ordering where documented.
- Test listener failures become structured diagnostics and preserve extension ownership.
- Test captcha provider contribution requires `captcha-provider` scope.
- Test active captcha provider resolution from lifecycle state.
- Test no active provider uses native skipped fallback.
- Test global captcha field renders non-visible fallback without blocking workflows.
- Test unique form/captcha instance IDs for multiple fields on one page.
- Test submitted payload delegation to active provider.
- Test verified, skipped, recoverable failure, suspicious failure, provider unavailable, and provider fault results.
- Test only verified provider-backed success can call scoped reset hooks.
- Test provider failure records safe diagnostics and does not expose internals.
- Test `security.captcha.provider` removal from settings registry, translations, tests, and rendered admin forms.
- Run focused PHPUnit suites for extension runtime, settings, form rendering/submission, rate-limit reset, and captcha bridge.
- Run `php bin/console lint:container` after service/configuration changes.
- Run focused `bin/lint` for changed PHP, Twig, YAML, and Markdown files.

## Documentation and tracking

- Update extension developer documentation for contribution phases, lazy contributions, runtime boot, activation factories, provider contributions, and public event listener contributions.
- Update Security and IconCaptcha drafts with final contract names and provider-selection policy.
- Update Security policy defaults if captcha success reset or provider-required policy changes.
- Update class map for new contracts, bridge services, event adapter classes, and form integration services.
- Update worklog with implementation slices and deferred follow-ups.
- Record any deferred event surfaces or provider-scope changes explicitly instead of hiding them inside the captcha implementation.

## Non-goals

- No IconCaptcha assets, JavaScript, CSS, challenge generation, refresh endpoint, or provider-specific payload schema.
- No third-party captcha provider.
- No automatic Symfony service registration for extension classes.
- No automatic extension-local Composer/vendor autoload registration.
- No ban on extension-owned internal service objects or manually bootstrapped extension-owned dependencies.
- No multi-active provider selection in the first implementation.
- No arbitrary Symfony event subscription surface for extensions.
- No process/shell execution from extension PHP outside a future operation-scoped contract.

## Acceptance criteria

- Extension authors can dynamically generate contributions without Symfony container service registration.
- Extension runtime boot, activation/install routines, event listeners, and provider processors have distinct documented phases and contracts.
- Core can invoke extension-owned code through explicit contribution contracts with ownership, scope, and failure diagnostics.
- Captcha-enabled workflows remain provider-agnostic and continue gracefully with no active provider.
- Activating a `captcha-provider` extension selects it as the active captcha provider without a duplicate settings key.
- Future IconCaptcha, ReCaptcha, or similar provider extensions can render their field, expose their live endpoints, own their assets, generate and validate challenges, and return documented captcha results without changing core form production code.
