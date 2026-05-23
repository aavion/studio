<?php

declare(strict_types=1);

namespace App\Core\Message;

final class MessageKey
{
    public const MANIFEST_UNREADABLE = 'message.manifest.unreadable';
    public const MANIFEST_INVALID_LINE = 'message.manifest.invalid_line';
    public const MANIFEST_INVALID_KEY = 'message.manifest.invalid_key';
    public const MANIFEST_DUPLICATE_KEY = 'message.manifest.duplicate_key';
    public const MANIFEST_MISSING_REQUIRED_KEY = 'message.manifest.missing_required_key';
    public const MANIFEST_UNKNOWN_KEY = 'message.manifest.unknown_key';
    public const MANIFEST_PARSED = 'message.manifest.parsed';
    public const MANIFEST_VALIDATED = 'message.manifest.validated';

    public const PACKAGE_MANIFEST_UNREADABLE = 'message.package.manifest_unreadable';
    public const PACKAGE_REQUIRED_FILE_MISSING = 'message.package.required_file_missing';
    public const PACKAGE_REQUIRED_DIRECTORY_MISSING = 'message.package.required_directory_missing';
    public const PACKAGE_FILE_UNREADABLE = 'message.package.file_unreadable';
    public const PACKAGE_PHP_SYNTAX_ERROR = 'message.package.php_syntax_error';
    public const PACKAGE_TWIG_SYNTAX_ERROR = 'message.package.twig_syntax_error';
    public const PACKAGE_JSON_SYNTAX_ERROR = 'message.package.json_syntax_error';
    public const PACKAGE_YAML_SYNTAX_ERROR = 'message.package.yaml_syntax_error';
    public const PACKAGE_CSS_SYNTAX_ERROR = 'message.package.css_syntax_error';
    public const PACKAGE_JAVASCRIPT_SYNTAX_ERROR = 'message.package.javascript_syntax_error';
    public const PACKAGE_COPY_SOURCE_MISSING = 'message.package.copy_source_missing';
    public const PACKAGE_COPY_SOURCE_SYMLINK = 'message.package.copy_source_symlink';
    public const PACKAGE_DISCOVERY_COMPLETED = 'message.package.discovery_completed';
    public const PACKAGE_VALIDATION_COMPLETED = 'message.package.validation_completed';
    public const PACKAGE_COPY_PLAN_CREATED = 'message.package.copy_plan_created';

    public const LINT_PHP_UNREADABLE = 'message.lint.php_unreadable';
    public const LINT_PHP_SYNTAX_ERROR = 'message.lint.php_syntax_error';
    public const LINT_TWIG_SYNTAX_ERROR = 'message.lint.twig_syntax_error';
    public const LINT_JSON_SYNTAX_ERROR = 'message.lint.json_syntax_error';
    public const LINT_YAML_SYNTAX_ERROR = 'message.lint.yaml_syntax_error';
    public const LINT_CSS_SYNTAX_ERROR = 'message.lint.css_syntax_error';
    public const LINT_JAVASCRIPT_SYNTAX_ERROR = 'message.lint.javascript_syntax_error';

    public const FILESYSTEM_SOURCE_MISSING = 'message.filesystem.source_missing';
    public const FILESYSTEM_SOURCE_SYMLINK = 'message.filesystem.source_symlink';
    public const FILESYSTEM_TARGET_SYMLINK = 'message.filesystem.target_symlink';
    public const FILESYSTEM_PARENT_SYMLINK = 'message.filesystem.parent_symlink';
    public const FILESYSTEM_FILE_EXISTS = 'message.filesystem.file_exists';
    public const FILESYSTEM_FILE_CONFLICT = 'message.filesystem.file_conflict';
    public const FILESYSTEM_DIRECTORY_CONFLICT = 'message.filesystem.directory_conflict';
    public const FILESYSTEM_PARENT_MISSING = 'message.filesystem.parent_missing';
    public const FILESYSTEM_PARENT_CREATE_FAILED = 'message.filesystem.parent_create_failed';
    public const FILESYSTEM_FILE_WRITE_FAILED = 'message.filesystem.file_write_failed';
    public const FILESYSTEM_FILE_COPY_FAILED = 'message.filesystem.file_copy_failed';
    public const FILESYSTEM_DIRECTORY_CREATE_FAILED = 'message.filesystem.directory_create_failed';
    public const FILESYSTEM_FILE_WRITTEN = 'message.filesystem.file_written';
    public const FILESYSTEM_FILE_COPIED = 'message.filesystem.file_copied';
    public const FILESYSTEM_DIRECTORY_READY = 'message.filesystem.directory_ready';
    public const FILESYSTEM_PARENT_DIRECTORY_READY = 'message.filesystem.parent_directory_ready';

    public const OPERATION_EXCEPTION = 'message.operation.exception';
    public const PROCESS_COMMAND_FAILED = 'message.process.command_failed';
    public const PROCESS_COMMAND_COMPLETED = 'message.process.command_completed';

    public const ACCESS_GRANTED = 'message.access.granted';
    public const ACCESS_DENIED = 'message.access.denied';
    public const ACCESS_LEVEL_INVALID = 'message.access.level.invalid';
    public const ACCESS_GROUP_IDENTIFIER_INVALID = 'message.access.group_identifier.invalid';
    public const CONFIG_KEY_INVALID = 'message.config.key.invalid';
    public const USERNAME_INVALID = 'message.user.username.invalid';
    public const USER_EMAIL_INVALID = 'message.user.email.invalid';
    public const API_KEY_PREFIX_INVALID = 'message.api_key.prefix.invalid';
    public const API_KEY_HMAC_HASH_INVALID = 'message.api_key.hmac_hash.invalid';
    public const API_KEY_ENCRYPTED_KEY_EMPTY = 'message.api_key.encrypted_key.empty';
    public const API_KEY_STATUS_INVALID = 'message.api_key.status.invalid';
    public const API_KEY_STATUS_READ_WRITE = 'message.api_key.status.read_write';
    public const API_KEY_STATUS_READ_ONLY = 'message.api_key.status.read_only';
    public const API_KEY_STATUS_REVOKED = 'message.api_key.status.revoked';
    public const API_KEY_CREATED = 'message.api_key.created';
    public const API_KEY_REVOKED = 'message.api_key.revoked';
    public const API_KEY_REVEALED = 'message.api_key.revealed';
    public const API_KEY_NOT_FOUND = 'message.api_key.not_found';
    public const API_KEY_AUTHENTICATION_FAILED = 'message.api_key.authentication_failed';
    public const API_KEY_REAUTHENTICATION_REQUIRED = 'message.api_key.reauthentication_required';
    public const API_KEY_PERMISSION_WRITE_REQUIRED = 'message.api_key.permission.write_required';
    public const API_KEY_PERMISSION_REVOKED = 'message.api_key.permission.revoked';
    public const PACKAGE_IDENTIFIER_INVALID = 'message.package.identifier.invalid';
    public const MENU_IDENTIFIER_INVALID = 'message.menu.identifier.invalid';

    public const CONTENT_SLUG_INVALID = 'message.content.slug.invalid_format';
    public const CONTENT_SLUG_RESERVED = 'message.content.slug.reserved';
    public const CONTENT_PATH_EMPTY_OR_PADDED = 'message.content.path.empty_or_padded';
    public const CONTENT_PATH_UNCLEAN = 'message.content.path.unclean';
    public const CONTENT_PATH_EMPTY_SEGMENT = 'message.content.path.empty_segment';
    public const CONTENT_PATH_RESERVED_PREFIX = 'message.content.path.reserved_prefix';
    public const CONTENT_PATH_TRAVERSAL = 'message.content.path.traversal';
    public const CONTENT_PATH_VARIANT_INVALID = 'message.content.path.variant_invalid';
    public const CONTENT_UID_INVALID = 'message.content.uid.invalid_format';
    public const CONTENT_STRING_LIST_EMPTY = 'message.content.string_list.empty';
    public const CONTENT_STRING_LIST_INVALID = 'message.content.string_list.invalid';
    public const CONTENT_METADATA_KEY_EMPTY = 'message.content.metadata.key_empty';
    public const CONTENT_METADATA_RESERVED_SCHEMA_FIELD = 'message.content.metadata.reserved_schema_field';
    public const CONTENT_FIELD_VALUE_VERSION_INVALID = 'message.content.field_value.version_invalid';
    public const CONTENT_LOCALE_TOKEN_INVALID = 'message.content.locale_token.invalid_format';
    public const CONTENT_FIELD_IDENTIFIER_INVALID = 'message.content.field_identifier.invalid_format';
    public const CONTENT_SCHEMA_IDENTIFIER_INVALID = 'message.content.schema.identifier_invalid';
    public const CONTENT_SCHEMA_VERSION_INVALID = 'message.content.schema.version_invalid';
    public const CONTENT_SCHEMA_REQUIRED_FIELD_MISSING = 'message.content.schema.required_field_missing';
    public const CONTENT_SCHEMA_FIELD_DUPLICATE = 'message.content.schema.field_duplicate';
}
