# Core architecture snippets

> **Status**: Draft  
> **Updated**: 2026-05-27  
> **Owner**: Core  
> **Purpose:** Collect practical implementation snippets, notes, and pseudocode for the first Core architecture before they are consolidated into contributor and user manuals.  

## Overview

This page is a working notebook for Core concepts that are already implemented but still likely to evolve before the first release candidate. Keep snippets small, concrete, and close to the code. Prefer adding notes here over prematurely maintaining parallel end-user and contributor guides.

## Database baseline

The initial Core database baseline includes reusable operational tables beyond content itself:

- `config_entry` stores global typed key/value configuration.
- `acl_group`, `user_account`, and `user_acl_group` prepare multi-group access control with access levels `0` through `9`; public level `0` is not stored as a group.
- `api_key` stores a display prefix, HMAC lookup hash, encrypted key payload, and read-only, read-write, or revoked status.
- `extension` tracks installed or discovered extensions and their scope list.
- `site_menu` and `site_menu_item` reserve the future menu model with target and view ACL metadata.

Structured logs remain filesystem-oriented. Database tables should hold state that needs querying or relationships; operational access, error, and security logs should use stable structured log records so they can later be converted or streamed as JSONL for UI filtering.

## Workflow results and messages

Use `WorkflowResult` for recoverable workflows. Hard failures, blocked actions, validation errors, and review-required states should stay structured and inspectable by future CLI, UI, importer, and action-log consumers. `Message` is the central feedback object. Use the `issues` slot for messages that affect the result state, usually `WARN`, `ERROR`, or `EXCEPTION`; use the `messages` slot for `SUCCESS`, `INFO`, or `DEBUG` events that should remain filterable in logs without turning into problems.

```php
$issue = Message::warning(
    'extension.required_file_missing',
    'message.extension.required_file_missing',
    ['%file%' => 'templates/base.html.twig'],
    ['file' => 'templates/base.html.twig'],
);

return WorkflowResult::invalid([$issue], [
    'extension' => $candidate->directory(),
]);
```

Current status intent:

| Status | Intent |
|--------|--------|
| `success` | The action or validation completed without issues. |
| `invalid` | Input or extension shape is wrong and should be fixed before retrying. |
| `requires_review` | The operation can continue only after explicit review or confirmation. |
| `blocked` | The operation was intentionally stopped because a guard condition failed. |
| `failed` | The operation attempted work and encountered an unrecoverable failure. |

## ActionQueue and dry-run flow

Use `ActionQueue` when a caller already knows the operations to perform. The executor keeps ordering deterministic, emits an `ActionLog`, and aggregates the highest-severity result status.

```php
$queue = ActionQueue::create('install extension', context: [
    'extension' => $candidate->directory(),
]);

$queue = $queue
    ->add(new EnsureDirectoryAction($projectDir, 'extensions/demo'))
    ->add(new CopyFileAction($candidate->directory(), 'templates/base.html.twig', $projectDir, 'extensions/demo/templates/base.html.twig'));

$executor = new OperationExecutor();
$plan = $executor->planQueue($queue);
$execution = $executor->executeQueue($queue);
```

Use the dry-run plan for previews. Use the execution result and action log for final status, diagnostics, UI summaries, and level-filtered log inspection. Completed high-level actions should generally emit `SUCCESS`; informational progress should emit `INFO`; noisy per-file or per-manifest details should emit `DEBUG`.

Live UI operations use the same `ActionQueue`/`OperationExecutor` path, but the queue is reconstructed by a detached `operations:run` console process instead of being executed inside the page request. The web request only creates a tokenized run record below `var/operations/{APP_ENV}`; `/api/live/operations/{id}?token=...` returns cursor-based ActionLog polling payloads for the overlay UI. Keep this route public but unguessable through the run token, because long operations may outlive the original authenticated browser session. The runner claims staged runs atomically, so duplicate console invocations cannot execute the same operation twice. Add new live operation types through `LiveOperationQueueProviderInterface` providers instead of extending the runner directly. Use `operations:cleanup` to remove expired terminal and stale run files.

Live operation providers must keep payloads small, serializable, and safe to persist temporarily. Validate payload shape inside the provider, resolve the actor or capability at the web entry point, and never place secrets, CSRF tokens, or raw request bodies in the payload, ActionLog context, or continuation data.

If a live operation needs review before it can continue, return `WorkflowResult::requiresReview()` with a translated `INFO` or `WARN` confirmation prompt and safe continuation metadata. The prompt must explain what the user is accepting or rejecting; error/exception issues alone are invalid for review prompts. The original runner must finish and release its lock. The UI may then start a new tokenized operation from the continuation instead of keeping PHP alive while waiting for the user.

Use `WARN` for recoverable or expected fallback behavior that does not leave the system in a broken state, such as content language/variant fallbacks or denied optional access. Use `ERROR` when something needs operator attention or a fix, such as faulty extensions, invalid extension manifests, missing required extension files, broken extension dependencies, or failed writes. Use `EXCEPTION` when a real `Throwable` was caught and converted into a structured message.

## Extension validation flow

Discovery should only find manifest-backed candidates. Validation should be caller-specific, because required files differ between the application, installable extensions, cached imports, and future installer workflows.

```php
$discovery = new ExtensionDiscovery();
$result = $discovery->discover($projectDir, $appEnv);

if (!$result->isSuccess()) {
    return $result;
}

$extensionSpec = ExtensionSpec::create()
    ->requireFile('.manifest')
    ->withLintingChecks();

foreach ($result->value() as $candidate) {
    if ('extension' !== $candidate->source()->name()) {
        continue;
    }

    $validationResult = (new ExtensionValidator())->validate($candidate, $extensionSpec);
}
```

Current extension manifests use `EXTENSION_*` keys. `EXTENSION_SCOPE` accepts a DotEnv-style list such as `[frontend-theme, system-template]` or a single value such as `module`. Every real extension must declare at least one identity scope: `module`, a theme scope such as `frontend-theme` or `backend-theme`, or a provider scope such as `captcha-provider`. Capability scopes such as `system-template`, `api`, `database`, `content-schema`, `scheduler-tasks`, and `operations` may be added as needed but do not make a manifest valid on their own.

## Fixture extensions

Reusable valid dummy extensions live under `tests/Fixtures/extensions/`. They intentionally mirror the standard discovery locations:

```text
tests/Fixtures/extensions/
  .manifest
  extensions/demo-theme/.manifest
  extensions/demo-module/.manifest
  var/cache/test/imports/demo-import/.manifest
```

Use these fixtures when a test needs stable extension discovery, linting, feature inspection, extension operation planning, or future installer dry-runs. Keep fixture files small and syntactically valid so broad preflight checks can run against them.

Intentionally invalid fixture extensions live under `tests/Fixtures/extensions-invalid/`. Use them for negative tests that should assert manifest parsing errors, missing required files or directories, and individual lint diagnostics without making the regular discovery fixtures fail.

## Issue-code notes

Messages have a stable log level and two stable identifiers:

- `MessageLevel` is log-filterable and uses `SUCCESS`, `EXCEPTION`, `ERROR`, `WARN`, `INFO`, or `DEBUG`.
- Domain-owned `*MessageCode` catalogues are machine-readable and useful for logs, branching, API clients, CLI exits, and extension integrations.
- Domain-owned `*MessageKey` catalogues are translation-facing and should resolve to localized UI, CLI, or log text later.

Runtime code should use `Message`, domain-owned message code/key catalogues, and the central `MessageCode::all()` / `MessageKey::all()` aggregators instead of embedding user-facing text in exceptions or operation payloads. Use `Message::invalidArgument()` or `MessageException::invalidArgument()` for hard invariant diagnostics that must still abort the current call.

Message catalogues follow an owner/scope convention:

- Constants live close to their owning domain, for example `App\Core\Extension\ExtensionMessageCode` and `App\Core\Extension\ExtensionMessageKey`.
- Constant names carry the domain scope, for example `EXTENSION_REQUIRED_FILE_MISSING`, `SETUP_PROMPT_LANGUAGE`, or `CONTENT_SLUG_INVALID`.
- Values stay in the matching machine namespace, for example `extension.required_file_missing` or `message.content.slug.invalid_format`.
- `App\Core\Message\MessageCode` and `App\Core\Message\MessageKey` aggregate the known system catalogues for validation, linting, translation checks, and future extension-catalogue adapters.

Core enforces a narrow transport shape:

```text
level
code
translation_key
parameters
context
```

Codes must either be generic uppercase tokens such as `E_INVALID_ARGUMENT` or namespaced lowercase tokens such as `extension.required_file_missing`. Translation keys must start with `message.`. Translation parameter names must use Symfony-friendly placeholder keys such as `%slug%`; free-form diagnostic data belongs in `context`. Default message levels are `SUCCESS` for success, `WARN` for `E_INVALID_ARGUMENT` and other diagnostics, and `ERROR` for other `E_*` codes unless the caller sets a level explicitly. Use `EXCEPTION` when a real `Throwable` was caught and converted into a structured message.

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
| `extension.*` | `extension.required_file_missing`, `extension.copy_source_symlink` | Discovery, validation, linting, and extension planning diagnostics. |
| `filesystem.*` | `filesystem.parent_symlink`, `filesystem.file_exists` | Root-scoped filesystem operation guards. |
| `operation.*` | `operation.exception` | Executor-level failures and exception mapping. |
| `process.*` | `process.command_completed`, `process.command_failed` | Process execution result diagnostics. |

Later UI layers can map these codes to translated messages while preserving the raw code for logs, audits, and debugging.

```php
$issue = Message::warning(
    ExtensionMessageCode::EXTENSION_REQUIRED_FILE_MISSING,
    ExtensionMessageKey::EXTENSION_REQUIRED_FILE_MISSING,
    ['%file%' => 'templates/base.html.twig'],
    ['file' => 'templates/base.html.twig'],
);
```

## References

- [Core architecture draft](../draft/0.1.x-CoreArchitecture.md)
- [Error handling and validation draft](../draft/0.1.x-ErrorHandlingValidation.md)
- [Extension developer guidelines](theme-module-developer-guidelines.md)
