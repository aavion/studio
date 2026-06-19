# Import dry-run snippets

> **Status**: Draft  
> **Updated**: 2026-05-23  
> **Owner**: Core  
> **Purpose:** Capture early dry-run and diff ideas for future extension, JSON, database, and operation imports.  

## Overview

Import workflows should be reviewable before they mutate files, entities, assets, or configuration. The current Core primitives already support dry-run plans, structured diffs, action queues, and action logs.

## File import sketch

```php
$planner = new ExtensionOperationPlanner();
$queueResult = $planner->copyFiles($candidate, $projectDir, $files, targetPrefix: 'extensions/demo');

if (!$queueResult->isSuccess()) {
    return $queueResult;
}

$executor = new OperationExecutor();
$plan = $executor->planQueue($queueResult->value());
```

The UI can render:

- action count;
- affected paths;
- highest risk;
- text diffs;
- extension features;
- preflight issues.

## Entity import sketch

Entity import is not implemented yet, but it should interact with the same structured diff shape.

```php
$before = [
    'slug' => 'old-title',
    'field_values' => [
        'title' => 'Old title',
    ],
];

$after = [
    'slug' => 'new-title',
    'field_values' => [
        'title' => 'New title',
    ],
];

$diff = (new KeyValueDiffGenerator())->diff($before, $after, [
    'entity' => ContentPage::class,
    'identifier' => 'page:home',
]);
```

Schema-driven variable fieldsets can use schema metadata to map field identifiers to labels, field types, and entity indices before rendering the diff.

## Review states

Use these buckets for future import screens:

- **Ready:** no issues, low-risk actions only.
- **Needs review:** diffs or moderate-risk actions require confirmation.
- **Blocked:** missing files, symlinks, invalid manifests, unsupported versions, or hard validation errors.
- **Failed:** execution attempted work and an action failed.

## Action log notes

Action logs should be emitted for dry-run summaries and final execution. Future persistence can store:

- operation name;
- actor or automation id;
- source extension/import id;
- status counts;
- issue payloads;
- context payload;
- timestamps and duration.

## References

- [Extension lifecycle snippets](extension-lifecycle-snippets.md)
- [Operation issue catalog](operation-issue-catalog.md)
- [Diff and review tools draft](../draft/0.3.x-DiffReviewTools.md)
- [Import/export and collaboration draft](../draft/0.4.x-ImportExportCollaboration.md)
