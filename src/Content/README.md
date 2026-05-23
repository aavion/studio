# Content

Static and dynamic content model code, content services, field schemas, and rendering coordination live here.

Content features should remain separated from editor workflows, theme resolution, and public delivery concerns unless a draft explicitly defines the boundary.

Implemented first-pass content primitives:

- `ContentStatus` and `ContentVisibility` define stable workflow and visibility tokens.
- `App\Core\Message\Message`, `MessageCode`, and `MessageKey` carry machine codes, translation keys, parameters, and context for later localization.
- `App\Core\Message\MessageException` carries a structured message while remaining compatible with `InvalidArgumentException`.
- `Routing\ContentSlug` validates public slug tokens.
- `Routing\ContentRouteGuard` protects reserved system route prefixes and normalizes content paths.
- `Schema\ContentSchemaField` defines reserved required base field identifiers such as `title` and `subtitle`.
- `Schema\ContentSchemaSource` classifies schema origins such as preset, custom, and module-provided schemas.

Doctrine entities live under `src/Entity/` to keep Symfony's default mapping conventions intact.
