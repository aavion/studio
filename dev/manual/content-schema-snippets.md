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
  created_at / created_by
  modified_at / modified_by

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
  created_at / created_by
  activated_at / activated_by

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
  created_at / created_by
  modified_at / modified_by
  published_at / published_by
  archived_at / archived_by
  deleted_at / deleted_by
  locked_at / locked_by
  metadata

content_revision
  uid
  content_uid
  version
  schema_uid
  schema_version_uid
  created_at / created_by
  change_summary
  metadata

content_field_value
  uid
  revision_uid
  language
  variant
  field_identifier
  field_content
```

`title` and `subtitle` are reserved required base fields in every content schema. They are stored as `content_field_value` rows through the variable fieldset, not as dedicated `content_item` columns and not in `content_item.metadata`.

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
