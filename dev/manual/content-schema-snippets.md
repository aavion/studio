# Content and schema snippets

> **Status**: Draft  
> **Updated**: 2026-05-23  
> **Owner**: Content  
> **Purpose:** Collect early notes for schema-driven fields, dynamic content, resolver behavior, and field-level diffs.

## Overview

The content model is not implemented yet. These notes capture decisions and assumptions that affect Core diffing, importer workflows, editor UI, and later database modeling.

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
```

Diff generators should not need to know domain entities directly. A future entity diff generator can use schema metadata to map values to field identifiers and display labels.

## Entity diff sketch

```php
$schema = $schemaRegistry->forContentType('landing_page');
$before = $entityReader->readFieldValues($entity, $schema);
$after = $importPayload->fieldValues();

$diff = $entityDiffGenerator->diff($schema, $before, $after);
```

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
