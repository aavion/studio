# Core architecture snippets

> **Status**: Draft  
> **Updated**: 2026-05-24  
> **Owner**: Core  
> **Purpose:** Collect practical implementation snippets, notes, and pseudocode for the first Core architecture before they are consolidated into contributor and user manuals.  

## Overview

This page is a working notebook for Core concepts that are already implemented but still likely to evolve before the first release candidate. Keep snippets small, concrete, and close to the code. Prefer adding notes here over prematurely maintaining parallel end-user and contributor guides.

## Database baseline

The initial Core database baseline includes reusable operational tables beyond content itself:

- `config_entry` stores global typed key/value configuration.
- `acl_group`, `user_account`, and `user_acl_group` prepare multi-group access control with access levels `0` through `9`; public level `0` is not stored as a group.
- `api_key` stores a display prefix, HMAC lookup hash, encrypted key payload, and read-only, read-write, or revoked status.
- `extension_package` tracks installed or discovered packages and their scope list.
- `site_menu` and `site_menu_item` reserve the future menu model with target and view ACL metadata.

Structured logs remain filesystem-oriented. Database tables should hold state that needs querying or relationships; operational access, error, and security logs should use stable structured log records so they can later be converted or streamed as JSONL for UI filtering.

## Workflow results and messages

Use `WorkflowResult` for recoverable workflows. Hard failures, blocked actions, validation errors, and review-required states should stay structured and inspectable by future CLI, UI, importer, and action-log consumers. `Message` is the central feedback object. Use the `issues` slot for messages that affect the result state, usually `WARN`, `ERROR`, or `EXCEPTION`; use the `messages` slot for `SUCCESS`, `INFO`, or `DEBUG` events that should remain filterable in logs without turning into problems.

```php
$issue = Message::warning(
    'package.required_file_missing',
    'message.package.required_file_missing',
    ['%file%' => 'templates/base.html.twig'],
    ['file' => 'templates/base.html.twig'],
);

return WorkflowResult::invalid([$issue], [
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
    ->add(new EnsureDirectoryAction($projectDir, 'packages/demo'))
    ->add(new CopyFileAction($candidate->directory(), 'templates/base.html.twig', $projectDir, 'packages/demo/templates/base.html.twig'));

$executor = new OperationExecutor();
$plan = $executor->planQueue($queue);
$execution = $executor->executeQueue($queue);
```

Use the dry-run plan for previews. Use the execution result and action log for final status, diagnostics, UI summaries, and level-filtered log inspection. Completed high-level actions should generally emit `SUCCESS`; informational progress should emit `INFO`; noisy per-file or per-manifest details should emit `DEBUG`.

Use `WARN` for recoverable or expected fallback behavior that does not leave the system in a broken state, such as content language/variant fallbacks or denied optional access. Use `ERROR` when something needs operator attention or a fix, such as faulty packages, invalid package manifests, missing required package files, broken package dependencies, or failed writes. Use `EXCEPTION` when a real `Throwable` was caught and converted into a structured message.

## Package validation flow

Discovery should only find manifest-backed candidates. Validation should be caller-specific, because required files differ between app, extension packages, cached imports, and future installer workflows.

```php
$discovery = new PackageDiscovery();
$result = $discovery->discover($projectDir, $appEnv);

if (!$result->isSuccess()) {
    return $result;
}

$packageSpec = PackageSpec::create()
    ->requireFile('.manifest')
    ->withLintingChecks();

foreach ($result->value() as $candidate) {
    if ('package' !== $candidate->source()->name()) {
        continue;
    }

    $validationResult = (new PackageValidator())->validate($candidate, $packageSpec);
}
```

Current package manifests use `PACKAGE_*` keys. `PACKAGE_SCOPE` accepts a DotEnv-style list such as `[frontend-theme, module]` or a single value such as `module`.

## Fixture packages

Reusable valid dummy packages live under `tests/Fixtures/packages/`. They intentionally mirror the standard discovery locations:

```text
tests/Fixtures/packages/
  .manifest
  packages/demo-theme/.manifest
  packages/demo-module/.manifest
  var/cache/test/imports/demo-import/.manifest
```

Use these fixtures when a test needs stable package discovery, linting, feature inspection, package operation planning, or future installer dry-runs. Keep fixture files small and syntactically valid so broad preflight checks can run against them.

Intentionally invalid fixture packages live under `tests/Fixtures/packages-invalid/`. Use them for negative tests that should assert manifest parsing errors, missing required files or directories, and individual lint diagnostics without making the regular discovery fixtures fail.

## Issue-code notes

Messages have a stable log level and two stable identifiers:

- `MessageLevel` is log-filterable and uses `SUCCESS`, `EXCEPTION`, `ERROR`, `WARN`, `INFO`, or `DEBUG`.
- `MessageCode` is machine-readable and useful for logs, branching, API clients, CLI exits, and package integrations.
- `MessageKey` is translation-facing and should resolve to localized UI, CLI, or log text later.

Runtime code should use `Message`, `MessageCode`, and `MessageKey` instead of embedding user-facing text in exceptions or operation payloads. Use `Message::invalidArgument()` or `MessageException::invalidArgument()` for hard invariant diagnostics that must still abort the current call.

Core enforces a narrow transport shape:

```text
level
code
translation_key
parameters
context
```

Codes must either be generic uppercase tokens such as `E_INVALID_ARGUMENT` or namespaced lowercase tokens such as `package.required_file_missing`. Translation keys must start with `message.`. Translation parameter names must use Symfony-friendly placeholder keys such as `%slug%`; free-form diagnostic data belongs in `context`. Default message levels are `SUCCESS` for success, `WARN` for `E_INVALID_ARGUMENT` and other diagnostics, and `ERROR` for other `E_*` codes unless the caller sets a level explicitly. Use `EXCEPTION` when a real `Throwable` was caught and converted into a structured message.

## ACL resolver flow

The shared ACL resolver evaluates normalized actors against capability-specific rules. Pass rules from nearest scope to broadest scope. The first explicit rule wins; inherited rules are skipped. If every rule inherits, the resolver falls back to the capability default: `view` and `use` require level `0`, `edit` requires level `3`, and `manage` requires level `6`.

```php
$actor = AccessActor::fromUserAccount($user);
$resolver = new AccessResolver();

$decision = $resolver->decide(
    $actor,
    AccessCapability::View,
    AccessRule::from($content->viewMinLevel(), $content->viewGroupIdentifiers()),
    AccessRule::from($parent->viewMinLevel(), $parent->viewGroupIdentifiers()),
);

if (!$decision->isGranted()) {
    return WorkflowResult::blocked([$decision->message()]);
}
```

| Prefix | Examples | Notes |
|--------|----------|-------|
| `manifest.*` | `manifest.missing_required_key` | Parser and manifest-spec diagnostics. |
| `package.*` | `package.required_file_missing`, `package.copy_source_symlink` | Discovery, validation, linting, and package planning diagnostics. |
| `filesystem.*` | `filesystem.parent_symlink`, `filesystem.file_exists` | Root-scoped filesystem operation guards. |
| `operation.*` | `operation.exception` | Executor-level failures and exception mapping. |
| `process.*` | `process.command_completed`, `process.command_failed` | Process execution result diagnostics. |

Later UI layers can map these codes to translated messages while preserving the raw code for logs, audits, and debugging.

```php
$issue = Message::warning(
    MessageCode::PACKAGE_REQUIRED_FILE_MISSING,
    MessageKey::PACKAGE_REQUIRED_FILE_MISSING,
    ['%file%' => 'templates/base.html.twig'],
    ['file' => 'templates/base.html.twig'],
);
```

## References

- [Core architecture draft](../draft/0.1.x-CoreArchitecture.md)
- [Error handling and validation draft](../draft/0.1.x-ErrorHandlingValidation.md)
- [Package developer guidelines](theme-module-developer-guidelines.md)
