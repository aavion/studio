# Core architecture snippets

> **Status**: Draft  
> **Updated**: 2026-05-22  
> **Owner**: Core  
> **Purpose:** Collect practical implementation snippets, notes, and pseudocode for the first Core architecture before they are consolidated into contributor and user manuals.

## Overview

This page is a working notebook for Core concepts that are already implemented but still likely to evolve before the first release candidate. Keep snippets small, concrete, and close to the code. Prefer adding notes here over prematurely maintaining parallel end-user and contributor guides.

## Operation results and issues

Use `OperationResult` for recoverable workflows. Hard failures, blocked actions, validation errors, and review-required states should stay structured and inspectable by future CLI, UI, importer, and action-log consumers.

```php
$issue = OperationIssue::create('package.required_file_missing', 'Required package file is missing.', [
    'file' => 'templates/base.html.twig',
]);

return OperationResult::invalid([$issue], [
    'package' => $candidate->directory(),
]);
```

Current status intent:

| Status | Intent |
|--------|--------|
| `success` | The action or validation completed without issues. |
| `invalid` | Input or package shape is wrong and should be fixed before retrying. |
| `requires_review` | The operation can continue only after explicit review or confirmation. |
| `blocked` | The operation was intentionally stopped because a guard condition failed. |
| `failed` | The operation attempted work and encountered an unrecoverable failure. |

## ActionQueue and dry-run flow

Use `ActionQueue` when a caller already knows the operations to perform. The executor keeps ordering deterministic, emits an `ActionLog`, and aggregates the highest-severity result status.

```php
$queue = ActionQueue::create('install package', context: [
    'package' => $candidate->directory(),
]);

$queue = $queue
    ->add(new EnsureDirectoryAction($projectDir, 'themes/demo'))
    ->add(new CopyFileAction($candidate->directory(), 'templates/base.html.twig', $projectDir, 'themes/demo/templates/base.html.twig'));

$executor = new OperationExecutor();
$plan = $executor->planQueue($queue);
$execution = $executor->executeQueue($queue);
```

Use the dry-run plan for previews. Use the execution result and action log for final status, diagnostics, and UI summaries.

## Package validation flow

Discovery should only find manifest-backed candidates. Validation should be caller-specific, because required files differ between app, theme, module, cached import, and future installer workflows.

```php
$discovery = new PackageDiscovery();
$result = $discovery->discover($projectDir, $appEnv);

if (!$result->isSuccess()) {
    return $result;
}

$themeSpec = PackageSpec::create()
    ->requireFile('.manifest')
    ->requireDirectory('templates')
    ->requireDirectory('assets')
    ->withLintingChecks();

foreach ($result->value() as $candidate) {
    if ('theme' !== $candidate->source()->name()) {
        continue;
    }

    $validationResult = (new PackageValidator())->validate($candidate, $themeSpec);
}
```

## Fixture packages

Reusable valid dummy packages live under `tests/Fixtures/packages/`. They intentionally mirror the standard discovery locations:

```text
tests/Fixtures/packages/
  .manifest
  themes/demo-theme/.manifest
  modules/demo-module/.manifest
  var/cache/test/imports/demo-import/.manifest
```

Use these fixtures when a test needs stable package discovery, linting, feature inspection, package operation planning, or future installer dry-runs. Keep fixture files small and syntactically valid so broad preflight checks can run against them.

Intentionally invalid fixture packages live under `tests/Fixtures/packages-invalid/`. Use them for negative tests that should assert manifest parsing errors, missing required files or directories, and individual lint diagnostics without making the regular discovery fixtures fail.

## Issue-code notes

Issue codes are stable developer-facing identifiers, not translated UI messages. Keep them deterministic and namespaced by subsystem:

| Prefix | Examples | Notes |
|--------|----------|-------|
| `manifest.*` | `manifest.missing_required_key` | Parser and manifest-spec diagnostics. |
| `package.*` | `package.required_file_missing`, `package.copy_source_symlink` | Discovery, validation, linting, and package planning diagnostics. |
| `filesystem.*` | `filesystem.parent_symlink`, `filesystem.file_exists` | Root-scoped filesystem operation guards. |
| `operation.*` | `operation.exception` | Executor-level failures and exception mapping. |

Later UI layers can map these codes to translated messages while preserving the raw code for logs, audits, and debugging.

## References

- [Core architecture draft](../draft/0.1.x-CoreArchitecture.md)
- [Error handling and validation draft](../draft/0.1.x-ErrorHandlingValidation.md)
- [Theme and module developer guidelines](theme-module-developer-guidelines.md)
