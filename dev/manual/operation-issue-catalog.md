# Operation issue catalog

> **Status**: Draft  
> **Updated**: 2026-05-23  
> **Owner**: Core  
> **Purpose:** Track current structured issue codes, expected meaning, and useful context keys for future UI, logs, audits, and translations.  

## Overview

Issue codes are developer-facing stable identifiers. They are not final UI copy and should not be translated directly in Core. Future UI layers can map translation keys to localized messages while preserving raw codes for logs and debugging. Runtime code should use `App\Core\Message\Message` with `MessageCode` and `MessageKey` constants so logs, output, validation, and future localization share one message shape.

The transport shape is:

```text
code
translation_key
parameters
context
```

Third-party modules and themes may provide their own codes and translation keys as long as they remain deterministic and namespaced.

Validation rules:

- Codes use either uppercase generic tokens, for example `E_INVALID_ARGUMENT`, or lowercase namespaced tokens, for example `package.required_file_missing`.
- Translation keys start with `message.`, for example `message.content.slug.invalid_format`.
- Translation parameters use placeholder names such as `%slug%`.
- Non-translated diagnostics, paths, raw output excerpts, and internal class names belong in `context`.

## Current codes

| Code | Meaning | Common context keys |
|------|---------|---------------------|
| `manifest.unreadable` | Manifest contents could not be split into lines. | none |
| `manifest.invalid_line` | Manifest line is not valid `KEY=VALUE` syntax. | `line` |
| `manifest.invalid_key` | Manifest key does not match key rules. | `line`, `key` |
| `manifest.duplicate_key` | Manifest key appears more than once. | `line`, `key` |
| `manifest.missing_required_key` | Required manifest key is absent or empty. | `key` |
| `manifest.unknown_key` | Closed manifest spec rejected an undeclared key. | `key` |
| `package.manifest_unreadable` | Manifest file exists but cannot be read. | `path`, `source` |
| `package.required_file_missing` | Required package file is absent. | `source`, `package`, `requirement`, `path` |
| `package.required_directory_missing` | Required package directory is absent. | `source`, `package`, `requirement`, `path` |
| `package.file_unreadable` | Package file could not be read for linting. | `source`, `package`, `file`, `path` |
| `package.php_syntax_error` | PHP linter found a syntax error. | `source`, `package`, `file`, `path` |
| `package.twig_syntax_error` | Twig linter found a syntax error. | `source`, `package`, `file`, `path` |
| `package.json_syntax_error` | JSON linter found a syntax error. | `source`, `package`, `file`, `path` |
| `package.yaml_syntax_error` | YAML linter found a syntax error. | `source`, `package`, `file`, `path` |
| `package.css_syntax_error` | CSS linter found a syntax error. | `source`, `package`, `file`, `path` |
| `package.javascript_syntax_error` | JavaScript linter found a syntax error. | `source`, `package`, `file`, `path` |
| `package.copy_source_missing` | Planned package copy source does not exist. | `source`, `package`, `file`, `path` |
| `package.copy_source_symlink` | Planned package copy source is a symlink. | `source`, `package`, `file`, `path` |
| `filesystem.source_missing` | Filesystem copy source is missing. | `source`, `target` |
| `filesystem.source_symlink` | Filesystem copy source is a symlink. | `source`, `target` |
| `filesystem.target_symlink` | Filesystem target path is a symlink. | `path`, `source`, `target` |
| `filesystem.parent_symlink` | Parent path segment is a symlink. | `path`, `source`, `target`, `parent` |
| `filesystem.file_exists` | Target file exists and overwrite is disabled. | `path`, `source`, `target` |
| `filesystem.file_conflict` | Directory/file conflict blocks file write or copy. | `path`, `source`, `target` |
| `filesystem.directory_conflict` | File exists where a directory is required. | `path` |
| `filesystem.parent_missing` | Parent directory is missing and creation is disabled. | `path`, `source`, `target`, `parent` |
| `filesystem.parent_create_failed` | Parent directory could not be created. | `path`, `source`, `target`, `parent` |
| `filesystem.file_write_failed` | File write returned failure. | `path` |
| `filesystem.file_copy_failed` | File copy returned failure. | `source`, `target` |
| `filesystem.directory_create_failed` | Directory creation returned failure. | `path` |
| `operation.exception` | Operation action threw an exception. | `action`, `type`, `exception`, `message` |
| `process.command_failed` | Process action exited with a non-zero status. | `command`, `exit_code`, `output`, `error_output` |
| `E_INVALID_ARGUMENT` | Input or domain argument failed validation. | varies by translation key |
| `SUCCESS` | Generic success marker for success messages. | varies by translation key |

## Current translation keys

| Translation key | Meaning | Common parameters |
|-----------------|---------|-------------------|
| `message.content.slug.invalid_format` | Content slug does not match the public slug rules. | `%slug%` |
| `message.content.slug.reserved` | Content slug conflicts with a reserved system route prefix. | `%slug%` |
| `message.content.path.empty_or_padded` | Content path is empty or padded with whitespace. | `%path%` |
| `message.content.path.unclean` | Content path contains unsupported URL characters. | `%path%` |
| `message.content.path.empty_segment` | Content path contains no usable segment or has empty segments. | `%path%` |
| `message.content.path.reserved_prefix` | Content path starts with a reserved system route prefix. | `%path%`, `%prefix%` |
| `message.content.path.traversal` | Content path contains `.` or `..` traversal. | `%path%`, `%segment%` |
| `message.content.path.variant_invalid` | Content path contains an invalid variant marker segment. | `%path%`, `%variant%` |
| `message.content.uid.invalid_format` | Content UID is not a lowercase UUID string. | `%label%`, `%uid%` |
| `message.content.string_list.empty` | A required string-list value is empty. | `%label%` |
| `message.content.string_list.invalid` | A string-list value contains a non-string or empty string. | `%label%` |
| `message.content.metadata.key_empty` | Metadata key is empty. | none |
| `message.content.metadata.reserved_schema_field` | Metadata key conflicts with a reserved schema field identifier. | `%field_identifier%` |
| `message.content.field_value.version_invalid` | Field value version is not positive. | `%version%` |
| `message.content.locale_token.invalid_format` | Locale token does not match the first-pass lowercase locale format. | `%label%`, `%token%` |
| `message.content.field_identifier.invalid_format` | Field identifier is not lowercase snake_case. | `%field_identifier%` |
| `message.content.schema.identifier_invalid` | Content schema identifier is not lowercase snake_case. | `%identifier%` |
| `message.content.schema.version_invalid` | Content schema version is not positive. | `%version%` |
| `message.content.schema.required_field_missing` | Content schema is missing a required base field. | `%field_identifier%` |
| `message.content.schema.field_duplicate` | Content schema contains a duplicate field identifier. | `%field_identifier%` |
| `message.access.level.invalid` | Access level is outside the supported 0-9 range. | `%level%` |
| `message.access.group_identifier.invalid` | ACL group identifier is not lowercase snake_case. | `%identifier%` |
| `message.config.key.invalid` | Configuration key does not use dotted lowercase segments. | `%key%` |
| `message.user.username.invalid` | Username does not match the supported account-name format. | `%username%` |
| `message.user.email.invalid` | User email address is invalid. | `%email%` |
| `message.api_key.prefix.invalid` | API key prefix does not match the safe display format. | `%prefix%` |
| `message.package.identifier.invalid` | Managed package identifier contains unsupported characters. | `%identifier%` |
| `message.menu.identifier.invalid` | Menu identifier is not lowercase snake_case. | `%identifier%` |

## Notes for future UI

- Use code prefixes for filters: `manifest`, `package`, `filesystem`, `operation`, `process`.
- Show context paths relative to the package or project when possible.
- Treat `blocked` and `failed` status as hard stop signals even when a queue is configured to continue.
- Keep raw issue arrays in action-log payloads for audit/debug views.

## References

- [Core architecture snippets](core-architecture-snippets.md)
- [Error handling and validation draft](../draft/0.1.x-ErrorHandlingValidation.md)
