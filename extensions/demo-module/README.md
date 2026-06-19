# Demo Module

Small inactive demo module used by local extension discovery and Admin Extension Management views.

When activated, it contributes configurable public demo routes, a dynamic after-content injection, scoped assets, translated demo copy, and extension-owned settings through `extension.php`.

The public `/demo` route is intentionally a lightweight showcase for Studio's native UI primitives and extension contracts. It demonstrates route configuration, frontend/backend shell previews, Markdown typography profiles, status badges, form controls, empty states, operation panels, and extension setting reads without registering production behavior.

Run the module's host-integration tests from the Studio root:

```bash
bin/phpunit extensions/demo-module/tests
```
