<?php

declare(strict_types=1);

namespace App\Core\Message;

final class MessageCode
{
    public const SUCCESS = 'SUCCESS';
    public const E_INVALID_ARGUMENT = 'E_INVALID_ARGUMENT';
    public const E_OPERATION_FAILED = 'E_OPERATION_FAILED';

    public const MANIFEST_UNREADABLE = 'manifest.unreadable';
    public const MANIFEST_INVALID_LINE = 'manifest.invalid_line';
    public const MANIFEST_INVALID_KEY = 'manifest.invalid_key';
    public const MANIFEST_DUPLICATE_KEY = 'manifest.duplicate_key';
    public const MANIFEST_MISSING_REQUIRED_KEY = 'manifest.missing_required_key';
    public const MANIFEST_UNKNOWN_KEY = 'manifest.unknown_key';

    public const PACKAGE_MANIFEST_UNREADABLE = 'package.manifest_unreadable';
    public const PACKAGE_REQUIRED_FILE_MISSING = 'package.required_file_missing';
    public const PACKAGE_REQUIRED_DIRECTORY_MISSING = 'package.required_directory_missing';
    public const PACKAGE_FILE_UNREADABLE = 'package.file_unreadable';
    public const PACKAGE_PHP_SYNTAX_ERROR = 'package.php_syntax_error';
    public const PACKAGE_PHP_NAMESPACE_INVALID = 'package.php_namespace_invalid';
    public const PACKAGE_TWIG_SYNTAX_ERROR = 'package.twig_syntax_error';
    public const PACKAGE_JSON_SYNTAX_ERROR = 'package.json_syntax_error';
    public const PACKAGE_YAML_SYNTAX_ERROR = 'package.yaml_syntax_error';
    public const PACKAGE_CSS_SYNTAX_ERROR = 'package.css_syntax_error';
    public const PACKAGE_JAVASCRIPT_SYNTAX_ERROR = 'package.javascript_syntax_error';
    public const PACKAGE_SCOPE_INVALID = 'package.scope_invalid';
    public const PACKAGE_TEMPLATE_PATH_INVALID = 'package.template_path_invalid';
    public const PACKAGE_COPY_SOURCE_MISSING = 'package.copy_source_missing';
    public const PACKAGE_COPY_SOURCE_SYMLINK = 'package.copy_source_symlink';
    public const PACKAGE_ASSET_SYNC_COMPLETED = 'package.asset_sync_completed';
    public const PACKAGE_ASSET_SYNC_FAILED = 'package.asset_sync_failed';
    public const PACKAGE_ASSET_REBUILD_QUEUED = 'package.asset_rebuild_queued';
    public const PACKAGE_ASSET_REBUILD_QUEUE_FAILED = 'package.asset_rebuild_queue_failed';
    public const EVENT_HOOK_INVALID = 'event.hook_invalid';
    public const EVENT_HOOK_UNREGISTERED = 'event.hook_unregistered';
    public const EVENT_HOOK_LISTENER_FAILED = 'event.hook_listener_failed';

    public const LINT_PHP_UNREADABLE = 'lint.php_unreadable';
    public const LINT_PHP_SYNTAX_ERROR = 'lint.php_syntax_error';
    public const LINT_TWIG_SYNTAX_ERROR = 'lint.twig_syntax_error';
    public const LINT_JSON_SYNTAX_ERROR = 'lint.json_syntax_error';
    public const LINT_YAML_SYNTAX_ERROR = 'lint.yaml_syntax_error';
    public const LINT_CSS_SYNTAX_ERROR = 'lint.css_syntax_error';
    public const LINT_JAVASCRIPT_SYNTAX_ERROR = 'lint.javascript_syntax_error';

    public const FILESYSTEM_SOURCE_MISSING = 'filesystem.source_missing';
    public const FILESYSTEM_SOURCE_SYMLINK = 'filesystem.source_symlink';
    public const FILESYSTEM_TARGET_SYMLINK = 'filesystem.target_symlink';
    public const FILESYSTEM_PARENT_SYMLINK = 'filesystem.parent_symlink';
    public const FILESYSTEM_FILE_EXISTS = 'filesystem.file_exists';
    public const FILESYSTEM_FILE_CONFLICT = 'filesystem.file_conflict';
    public const FILESYSTEM_DIRECTORY_CONFLICT = 'filesystem.directory_conflict';
    public const FILESYSTEM_PARENT_MISSING = 'filesystem.parent_missing';
    public const FILESYSTEM_PARENT_CREATE_FAILED = 'filesystem.parent_create_failed';
    public const FILESYSTEM_FILE_WRITE_FAILED = 'filesystem.file_write_failed';
    public const FILESYSTEM_FILE_COPY_FAILED = 'filesystem.file_copy_failed';
    public const FILESYSTEM_DIRECTORY_CREATE_FAILED = 'filesystem.directory_create_failed';
    public const FILESYSTEM_FILE_WRITTEN = 'filesystem.file_written';
    public const FILESYSTEM_FILE_COPIED = 'filesystem.file_copied';
    public const FILESYSTEM_PATH_REMOVED = 'filesystem.path_removed';
    public const FILESYSTEM_DIRECTORY_READY = 'filesystem.directory_ready';
    public const FILESYSTEM_PARENT_DIRECTORY_READY = 'filesystem.parent_directory_ready';

    public const OPERATION_EXCEPTION = 'operation.exception';
    public const PROCESS_COMMAND_FAILED = 'process.command_failed';
    public const PROCESS_COMMAND_COMPLETED = 'process.command_completed';
    public const MANIFEST_PARSED = 'manifest.parsed';
    public const MANIFEST_VALIDATED = 'manifest.validated';
    public const PACKAGE_DISCOVERY_QUEUED = 'package.discovery_queued';
    public const PACKAGE_DISCOVERY_QUEUE_FAILED = 'package.discovery_queue_failed';
    public const PACKAGE_DISCOVERY_COMPLETED = 'package.discovery_completed';
    public const PACKAGE_VALIDATION_COMPLETED = 'package.validation_completed';
    public const PACKAGE_COPY_PLAN_CREATED = 'package.copy_plan_created';
    public const PACKAGE_REGISTRY_SYNC_COMPLETED = 'package.registry.sync_completed';
    public const PACKAGE_REGISTRY_PACKAGE_REGISTERED = 'package.registry.registered';
    public const PACKAGE_REGISTRY_PACKAGE_UPDATED = 'package.registry.updated';
    public const PACKAGE_REGISTRY_PACKAGE_REMOVED = 'package.registry.removed';
    public const PACKAGE_REGISTRY_PACKAGE_FAULTY = 'package.registry.faulty';
    public const PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND = 'package.lifecycle.not_found';
    public const PACKAGE_LIFECYCLE_STATUS_BLOCKED = 'package.lifecycle.status_blocked';
    public const PACKAGE_LIFECYCLE_ACTIVATED = 'package.lifecycle.activated';
    public const PACKAGE_LIFECYCLE_DEACTIVATED = 'package.lifecycle.deactivated';
    public const PACKAGE_LIFECYCLE_CLEANUP_COMPLETED = 'package.lifecycle.cleanup_completed';
    public const PACKAGE_LIFECYCLE_REMOVED = 'package.lifecycle.removed';
    public const PACKAGE_LIFECYCLE_PURGED = 'package.lifecycle.purged';
    public const PACKAGE_LIFECYCLE_FAULT_RESET = 'package.lifecycle.fault_reset';
    public const PACKAGE_LIFECYCLE_RUNTIME_FAILURE = 'package.lifecycle.runtime_failure';
    public const PACKAGE_LIFECYCLE_PHP_LOAD_FAILED = 'package.lifecycle.php_load_failed';
    public const PACKAGE_LIFECYCLE_ROLLED_BACK = 'package.lifecycle.rolled_back';
    public const PACKAGE_DEPENDENCY_MISSING = 'package.dependency.missing';
    public const PACKAGE_DEPENDENCY_VERSION_UNSATISFIED = 'package.dependency.version_unsatisfied';
    public const PACKAGE_DEPENDENCY_STATUS_BLOCKED = 'package.dependency.status_blocked';
    public const ACCESS_GRANTED = 'access.granted';
    public const ACCESS_DENIED = 'access.denied';
    public const CONTENT_LANGUAGE_FALLBACK = 'content.language_fallback';
    public const CONTENT_VARIANT_FALLBACK = 'content.variant_fallback';
    public const SETUP_STEP_FAILED = 'setup.step_failed';
    public const SETUP_LANGUAGE_SELECTED = 'setup.language_selected';
    public const SETUP_AVAILABLE_LANGUAGES = 'setup.available_languages';
    public const SETUP_DRY_RUN = 'setup.dry_run';
    public const SETUP_PROMPT_LANGUAGE = 'setup.prompt.language';
    public const SETUP_PROMPT_SITE_TITLE = 'setup.prompt.site_title';
    public const SETUP_PROMPT_DEFAULT_URI = 'setup.prompt.default_uri';
    public const SETUP_PROMPT_DATABASE_DRIVER = 'setup.prompt.database_driver';
    public const SETUP_PROMPT_DATABASE_URL = 'setup.prompt.database_url';
    public const SETUP_PROMPT_DATABASE_HOST = 'setup.prompt.database_host';
    public const SETUP_PROMPT_DATABASE_PORT = 'setup.prompt.database_port';
    public const SETUP_PROMPT_DATABASE_NAME = 'setup.prompt.database_name';
    public const SETUP_PROMPT_DATABASE_USER = 'setup.prompt.database_user';
    public const SETUP_PROMPT_DATABASE_PASSWORD = 'setup.prompt.database_password';
    public const SETUP_PROMPT_ADMIN_USERNAME = 'setup.prompt.admin_username';
    public const SETUP_PROMPT_ADMIN_PASSWORD = 'setup.prompt.admin_password';
    public const SETUP_PROMPT_ADMIN_PASSWORD_CONFIRM = 'setup.prompt.admin_password_confirm';
    public const SETUP_PROMPT_ADMIN_EMAIL = 'setup.prompt.admin_email';
    public const SETUP_PROMPT_APP_SECRET = 'setup.prompt.app_secret';
    public const SETUP_PROMPT_INVALID_CHOICE = 'setup.prompt.invalid_choice';
    public const SETUP_PROMPT_PASSWORD_MISMATCH = 'setup.prompt.password_mismatch';
    public const SETUP_PROMPT_PASSWORD_RESET_CONTINUE = 'setup.prompt.password_reset_continue';
    public const SETUP_PROMPT_PASSWORD_RESET_NEW_PASSWORD = 'setup.prompt.password_reset_new_password';
    public const SETUP_PROMPT_PASSWORD_RESET_CONFIRM_PASSWORD = 'setup.prompt.password_reset_confirm_password';
}
