# Content and schema snippets

> **Status**: Draft  
> **Updated**: 2026-05-23  
> **Owner**: Core  
> **Purpose:** Collect early notes for schema-driven fields, dynamic content, resolver behavior, and field-level diffs.  

## Overview

The first content persistence baseline is implemented through `ContentSchema`, `ContentSchemaVersion`, `ContentItem`, `ContentRevision`, and `ContentFieldValue`. These notes capture decisions and assumptions that affect Core diffing, importer workflows, editor UI, and later database modeling.

## Product model

Content schemas are database-backed content-type definitions. Factory presets should make the CMS fully usable immediately after installation, while editable custom schemas let users define project-specific fieldsets, expected values, validation, localization behavior, relationships, and optional inner Twig rendering without changing PHP code or database schema.

## Persistence baseline

The initial tables keep schema definitions, content identity, routing, workflow, permission, and variant metadata explicit while keeping non-field metadata flexible:

```text
content_schema
  uid
  identifier
  source
  locked
  active_version_uid
  labels
  descriptions
  metadata

content_schema_version
  uid
  schema_uid
  version
  title
  description
  definition
  custom_twig
  definition_hash
  use_min_level / use_group_identifiers
  edit_min_level / edit_group_identifiers
  manage_min_level / manage_group_identifiers
  metadata

content_item
  uid
  slug
  status
  parent_uid
  sort_order
  custom_url
  redirect_target
  schema_uid
  schema_version
  active_revision_uid
  version
  available_languages
  available_variants
  visibility
  acl_restrictions
  view_min_level / view_group_identifiers
  edit_min_level / edit_group_identifiers
  manage_min_level / manage_group_identifiers
  metadata

content_revision
  uid
  content_uid
  version
  schema_uid
  schema_version_uid
  change_summary
  metadata

content_field_value
  uid
  revision_uid
  language
  variant
  field_identifier
  field_content

state_marker
  uid
  subject_type
  subject_uid
  marker_key
  marker_at
  marker_by
  marker_value
  metadata
```

`title` and `subtitle` are reserved required base fields in every content schema. They are stored as `content_field_value` rows through the variable fieldset, not as dedicated `content_item` columns and not in `content_item.metadata`.

`parent_uid` usually stores another content UID. The reserved value `system` is the only virtual parent marker and is used for internal `/system/...` content routes. These entities may be resolved by internal services, but the public catch-all route must reject direct browser delivery for the `system` prefix.

`redirect_target` currently stores the redirect target string. Code may expose this as `redirectRoute()` while the database column keeps its pre-rename name until the routing model is reshaped. Internal targets such as `/system/footer` render the target content without changing the browser URL. External `http://` or `https://` targets return `302 Found`; other URI schemes are rejected.

`/api/live/**` is reserved for small application-owned JSON flows such as captcha seeds, polling endpoints, and live operation status. These routes should use Symfony controllers and the shared JSON output renderer instead of the public content catch-all. Long-lived external API contracts belong under versioned prefixes such as `/api/v1/**`.

Error-page handling should use a layered fallback later: when an error such as `404`, `403`, `429`, `451`, or maintenance-mode `503` occurs, the handler should first look for a matching internal content entity such as `/system/error-pages/404` or `/system/error-pages/503`; if none exists or it cannot be rendered, the active system theme should provide the default error page.

Generic route variants use a trailing marker segment such as `/article/~compact`. The marker is not part of the content hierarchy; lookup resolves `/article` and passes `compact` as the requested variant. Missing variants fall back to the default variant when possible and add a warning message to the resolve result for later logging.

The public start page is configured through `content.home_path`, defaulting to `/home`. Root URLs such as `/` and localized roots such as `/de` render that path internally; the content model therefore does not require an empty slug or reserved homepage slug.

Localized public routes are controlled by `localization.route_prefixes_enabled`. When enabled, the best matching browser language from `Accept-Language` is used for unprefixed redirects when available; otherwise `localization.default_language` is used. For example, `/` redirects to `/de` and `/about` redirects to `/de/about` when `de` is selected. Available prefixes are discovered from `translations/messages.*.yaml`, and active language prefixes are reserved content slugs while localized routing is enabled.

`state_marker` is the reusable fast-lookup layer for current/last lifecycle metadata across content items, revisions, schemas, schema versions, users, and ACL groups. For example, content publication uses `marker_key=published`, `marker_at`, and `marker_by`; user login self-audit uses `marker_key=last_login`, `marker_by=NULL`, and `marker_value` for the IPv4/IPv6 address. It is intentionally not a full audit log; long history belongs to the later audit/logging layer.

`active_version_uid` and `active_revision_uid` are nullable on purpose. `NULL` disables a schema or content entity from normal use/rendering while keeping the record available for admin recovery, staging, or cleanup retention.

ACL overrides use explicit level columns plus optional group-identifier lists. `NULL` means inherit. An empty group list means no group exception, so only the level rule applies.

Localized titles live in revision field values. For portable list views across MariaDB/MySQL, SQLite, and PostgreSQL, common display/search fields should later be projected into an explicit read model or index table instead of relying on vendor-specific JSON operators against `field_content`.

## Validation messages

Content-domain validation should not throw free-form user-facing sentences. Use a structured `Message` shape: a machine-readable `MessageCode`, a translatable `MessageKey`, parameters for translators, and optional context for logs or diagnostics.

```php
throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, MessageKey::CONTENT_SLUG_INVALID, [
    '%slug%' => $slug,
], [
    'field' => 'slug',
]);
```

Later form, validator, and UI layers can translate the key, while logs, CLI output, and API/debug views can keep the code and context.

## Fieldset idea

Schema-driven fieldsets may define fields with:

```text
identifier
entity
entity_index
type
label
required
localized
validation
storage
rendering
custom_twig
```

Every schema must include `title` and `subtitle` as required field identifiers. This keeps localization, previews, menus, generic rendering, API output, and import/export aligned around schema-backed values instead of parallel metadata conventions.

Diff generators should not need to know domain entities directly. A future entity diff generator can use schema metadata to map values to field identifiers and display labels.

## Entity diff sketch

```php
$schema = $schemaRegistry->forContentType('landing_page');
$before = $entityReader->readFieldValues($entity, $schema);
$after = $importPayload->fieldValues();

$diff = $entityDiffGenerator->diff($schema, $before, $after);
```

Imports can stage proposed entity changes as new revisions, generate structured diffs between the active revision and staged revision, and activate the staged revisions only after review. Revert can switch the active revision pointer back to an older retained revision.

## Resolver notes

Resolver tokens should eventually support:

- explicit references;
- depth limits;
- loop protection;
- ACL-aware resolution;
- export/import normalization;
- diagnostics for missing or inaccessible targets.

## Editor notes

The editor should later be able to lint:

- YAML snippets;
- JSON snippets;
- Twig fragments where allowed;
- resolver tokens;
- schema-defined field constraints.

## References

- [Import dry-run snippets](import-dry-run-snippets.md)
- [Schema content fields draft](../draft/0.3.x-SchemaContentFields.md)
- [Static/dynamic content draft](../draft/0.1.x-StaticDynamicContent.md)
- [Cross-reference index and search draft](../draft/0.3.x-CrossReferenceIndexSearch.md)
- [Editor experience draft](../draft/0.3.x-EditorExperience.md)
