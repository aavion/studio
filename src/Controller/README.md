# Controller

Symfony controllers belong here.

Core code should stay small and behavior-oriented. Add shared abstractions only after repeated feature-local patterns prove that a common shape is useful.

Implemented controller entry points:

- `PublicContentController` provides the low-priority root and catch-all public content routes. It only coordinates request path validation, published content resolution, HTTP status mapping for missing or denied content, and the temporary neutral Twig response; theme selection and final rendering belong to the theme layer.
