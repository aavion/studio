# Content

Static and dynamic content model code, content services, field schemas, and rendering coordination live here.

Content features should remain separated from editor workflows, theme resolution, and public delivery concerns unless a draft explicitly defines the boundary.

Implemented first-pass content primitives:

- `ContentStatus` and `ContentVisibility` define stable workflow and visibility tokens.
- `App\Core\Message\Message`, `MessageCode`, and `MessageKey` carry machine codes, translation keys, parameters, and context for later localization.
- `App\Core\Message\MessageException` carries a structured message for hard invariant boundaries while extending PHP's `InvalidArgumentException`.
- `Read\ContentReadContextResolver` resolves the language and variant context for public content reads.
- `Read\PublishedContentResolver` loads published content views from slugs, custom URLs, or hierarchy paths while enforcing active revisions, visibility, and view ACL rules.
- `Read\PublishedContentResolveResult` and `Read\PublishedContentResolveStatus` distinguish missing/unpublished content from private or ACL-denied content for HTTP status mapping.
- `Read\PublishedContentView` exposes revision-scoped field values, including reserved `title` and `subtitle` field helpers.
- `App\Controller\PublicContentController` connects the public root and catch-all routes to the published content read layer with a temporary neutral Twig response.
- `Routing\ContentRouteLocalization` resolves optional language prefixes, browser-language/default redirects, configured home paths, and translation-derived protected route prefixes.
- `Routing\ContentPathLookup` resolves unrestricted content paths for public reads, redirects, and internal `/system/...` lookups.
- `Routing\ContentRedirectResolver` resolves content redirect targets with internal-route rendering, external `302` URLs, chain following, loop detection, hop limits, and unsupported-scheme rejection.
- `Routing\ContentRoutePath` parses public content paths and extracts trailing generic variant markers such as `~compact`.
- `Routing\ContentSlug` validates public slug tokens.
- `Routing\ContentRouteGuard` protects reserved system route prefixes and normalizes content paths.
- `Routing\ContentSystemRoute` defines the root parent sentinel and the internal-only `/system/...` prefix with its virtual parent marker.
- `Schema\ContentSchemaField` defines reserved required base field identifiers such as `title` and `subtitle`.
- `Schema\ContentSchemaSource` classifies schema origins such as preset, custom, and module-provided schemas.

Doctrine entities live under `src/Entity/` to keep Symfony's default mapping conventions intact.
