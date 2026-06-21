# Extensions

Installable extensions live here. Discovery reads only direct child extension directories with a `.manifest` file and does not include extension PHP during discovery.

Extensions use `EXTENSION_*` manifest keys and declare one or more scopes through `EXTENSION_SCOPE`, for example `[frontend-theme, module]`.

Active extensions may include an optional `extension.php` runtime loader. The loader is included only after the extension has been validated and activated. It may return supported contribution DTOs/providers or a callable that does the same. Supported direct contribution DTOs currently include static view injections, dynamic view injections, and extension setting definitions. Extension PHP must not directly access filesystem, process, network, request-context, or environment capabilities; use documented extension points instead. Extension PHP should use the declared `EXTENSION_NAMESPACE` root or one of its child namespaces. Loader failures are converted into structured lifecycle diagnostics and mark the extension `faulty` instead of breaking the request with a raw exception.

Runtime extension points are Symfony-native and must be documented before extensions rely on them. Public event hooks are listed by `App\Core\Event\PublicEventHookRegistry`; unlisted Symfony events are internal implementation details. Core public dispatch points use `App\Core\Event\PublicEventDispatcher` so listener failures become structured diagnostics instead of raw errors. Hook listener failures also emit the internal `App\Core\Event\PublicHookFailedEvent` for later lifecycle/logging subscribers.
