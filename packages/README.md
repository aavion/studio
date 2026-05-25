# Packages

Installable extension packages live here. Discovery reads only direct child package directories with a `.manifest` file and does not include package PHP during discovery.

Packages use `PACKAGE_*` manifest keys and declare one or more scopes through `PACKAGE_SCOPE`, for example `[frontend-theme, module]`.

Active packages may include an optional `package.php` runtime loader. The loader is included only after the package has been validated and activated. It may load further package-owned PHP files below `src/`, which should use the declared `PACKAGE_NAMESPACE` root or one of its child namespaces. Loader failures are converted into structured lifecycle diagnostics and mark the package `faulty` instead of breaking the request with a raw exception.

Runtime extension points are Symfony-native and must be documented before packages rely on them. Public event hooks are listed by `App\Core\Event\PublicEventHookRegistry`; unlisted Symfony events are internal implementation details. Core public dispatch points use `App\Core\Event\PublicEventDispatcher` so listener failures become structured diagnostics instead of raw errors. Hook listener failures also emit the internal `App\Core\Event\PublicHookFailedEvent` for later lifecycle/logging subscribers.
