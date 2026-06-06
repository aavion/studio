<?php

declare(strict_types=1);

namespace App\Setup;

final class SetupMessageKey
{
    public const SETUP_STEP_FAILED = 'message.setup.step_failed';
    public const SETUP_ENVIRONMENT_FILE_UNREADABLE = 'message.setup.environment_file_unreadable';
    public const SETUP_ENVIRONMENT_FILE_WRITE_FAILED = 'message.setup.environment_file_write_failed';
    public const SETUP_LANGUAGE_SELECTED = 'message.setup.language_selected';
    public const SETUP_AVAILABLE_LANGUAGES = 'message.setup.available_languages';
    public const SETUP_ROLLBACK_COMPLETED = 'message.setup.rollback_completed';
    public const SETUP_DRY_RUN = 'message.setup.dry_run';
    public const SETUP_COMPOSER_UNAVAILABLE = 'message.setup.composer_unavailable';
    public const SETUP_RUNTIME_COMMAND_FAILED = 'message.setup.runtime_command_failed';
    public const SETUP_PHP_CLI_UNAVAILABLE = 'message.setup.php_cli_unavailable';
    public const SETUP_DATABASE_URL_SCHEME_MISSING = 'message.setup.database_url.scheme_missing';
    public const SETUP_DATABASE_URL_SQLITE_FORMAT_INVALID = 'message.setup.database_url.sqlite_format_invalid';
    public const SETUP_DATABASE_URL_SCHEME_UNSUPPORTED = 'message.setup.database_url.scheme_unsupported';
    public const SETUP_OUTPUT_SUCCESS = 'message.setup.output.success';
    public const SETUP_OUTPUT_FAILED = 'message.setup.output.failed';
    public const SETUP_INPUT_APP_ENV_EMPTY = 'message.setup.input.app_env_empty';
    public const SETUP_INPUT_LANGUAGE_INVALID = 'message.setup.input.language_invalid';
    public const SETUP_INPUT_LANGUAGE_UNAVAILABLE = 'message.setup.input.language_unavailable';
    public const SETUP_INPUT_SITE_TITLE_EMPTY = 'message.setup.input.site_title_empty';
    public const SETUP_INPUT_DEFAULT_URI_EMPTY = 'message.setup.input.default_uri_empty';
    public const SETUP_INPUT_DEFAULT_URI_INVALID = 'message.setup.input.default_uri_invalid';
    public const SETUP_INPUT_DATABASE_URL_DRIVER_MISMATCH = 'message.setup.input.database_url_driver_mismatch';
    public const SETUP_INPUT_DATABASE_PREFIX_INVALID = 'message.setup.input.database_prefix_invalid';
    public const SETUP_INPUT_ADMIN_USERNAME_INVALID = 'message.setup.input.admin_username_invalid';
    public const SETUP_INPUT_ADMIN_EMAIL_INVALID = 'message.setup.input.admin_email_invalid';
    public const SETUP_PROMPT_LANGUAGE = 'message.setup.prompt.language';
    public const SETUP_PROMPT_SITE_TITLE = 'message.setup.prompt.site_title';
    public const SETUP_PROMPT_DEFAULT_URI = 'message.setup.prompt.default_uri';
    public const SETUP_PROMPT_DATABASE_DRIVER = 'message.setup.prompt.database_driver';
    public const SETUP_PROMPT_DATABASE_URL = 'message.setup.prompt.database_url';
    public const SETUP_PROMPT_DATABASE_HOST = 'message.setup.prompt.database_host';
    public const SETUP_PROMPT_DATABASE_PORT = 'message.setup.prompt.database_port';
    public const SETUP_PROMPT_DATABASE_NAME = 'message.setup.prompt.database_name';
    public const SETUP_PROMPT_DATABASE_USER = 'message.setup.prompt.database_user';
    public const SETUP_PROMPT_DATABASE_PASSWORD = 'message.setup.prompt.database_password';
    public const SETUP_PROMPT_ADMIN_USERNAME = 'message.setup.prompt.admin_username';
    public const SETUP_PROMPT_ADMIN_PASSWORD = 'message.setup.prompt.admin_password';
    public const SETUP_PROMPT_ADMIN_PASSWORD_CONFIRM = 'message.setup.prompt.admin_password_confirm';
    public const SETUP_PROMPT_ADMIN_EMAIL = 'message.setup.prompt.admin_email';
    public const SETUP_PROMPT_APP_SECRET = 'message.setup.prompt.app_secret';
    public const SETUP_PROMPT_INVALID_CHOICE = 'message.setup.prompt.invalid_choice';
    public const SETUP_PROMPT_PASSWORD_MISMATCH = 'message.setup.prompt.password_mismatch';
    public const SETUP_ADMIN_PASSWORD_TOO_SHORT = 'message.setup.admin_password.too_short';
    public const SETUP_ADMIN_PASSWORD_COMPLEXITY = 'message.setup.admin_password.complexity';
    public const SETUP_ADMIN_PASSWORD_REPEATED = 'message.setup.admin_password.repeated';
    public const SETUP_ADMIN_PASSWORD_PERSONAL = 'message.setup.admin_password.personal';
    public const SETUP_APP_SECRET_TOO_SHORT = 'message.setup.app_secret.too_short';
    public const SETUP_PROMPT_PASSWORD_RESET_CONTINUE = 'message.setup.prompt.password_reset_continue';
    public const SETUP_PROMPT_PASSWORD_RESET_NEW_PASSWORD = 'message.setup.prompt.password_reset_new_password';
    public const SETUP_PROMPT_PASSWORD_RESET_CONFIRM_PASSWORD = 'message.setup.prompt.password_reset_confirm_password';
    public const SETUP_PASSWORD_RESET_DATABASE_URL_MISSING = 'message.setup.password_reset.database_url_missing';
    public const SETUP_PASSWORD_RESET_USER_NOT_FOUND = 'message.setup.password_reset.user_not_found';
    public const SETUP_PASSWORD_RESET_CANCELLED = 'message.setup.password_reset.cancelled';
    public const SETUP_PASSWORD_RESET_COMPLETED = 'message.setup.password_reset.completed';
    public const SETUP_PASSWORD_RESET_CONFIRM_REQUIRED = 'message.setup.password_reset.confirm_required';
}
