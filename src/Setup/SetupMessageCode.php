<?php

declare(strict_types=1);

namespace App\Setup;

final class SetupMessageCode
{
    public const SETUP_STEP_FAILED = 'setup.step_failed';
    public const SETUP_ENVIRONMENT_FILE_UNREADABLE = 'setup.environment_file_unreadable';
    public const SETUP_ENVIRONMENT_FILE_WRITE_FAILED = 'setup.environment_file_write_failed';
    public const SETUP_LANGUAGE_SELECTED = 'setup.language_selected';
    public const SETUP_AVAILABLE_LANGUAGES = 'setup.available_languages';
    public const SETUP_ROLLBACK_COMPLETED = 'setup.rollback_completed';
    public const SETUP_DRY_RUN = 'setup.dry_run';
    public const SETUP_COMPOSER_UNAVAILABLE = 'setup.composer_unavailable';
    public const SETUP_RUNTIME_COMMAND_FAILED = 'setup.runtime_command_failed';
    public const SETUP_PHP_CLI_UNAVAILABLE = 'setup.php_cli_unavailable';
    public const SETUP_DATABASE_URL_SCHEME_MISSING = 'setup.database_url.scheme_missing';
    public const SETUP_DATABASE_URL_SQLITE_FORMAT_INVALID = 'setup.database_url.sqlite_format_invalid';
    public const SETUP_DATABASE_URL_SCHEME_UNSUPPORTED = 'setup.database_url.scheme_unsupported';
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
    public const SETUP_ADMIN_PASSWORD_TOO_SHORT = 'setup.admin_password.too_short';
    public const SETUP_ADMIN_PASSWORD_COMPLEXITY = 'setup.admin_password.complexity';
    public const SETUP_ADMIN_PASSWORD_REPEATED = 'setup.admin_password.repeated';
    public const SETUP_ADMIN_PASSWORD_PERSONAL = 'setup.admin_password.personal';
    public const SETUP_APP_SECRET_TOO_SHORT = 'setup.app_secret.too_short';
    public const SETUP_PROMPT_PASSWORD_RESET_CONTINUE = 'setup.prompt.password_reset_continue';
    public const SETUP_PROMPT_PASSWORD_RESET_NEW_PASSWORD = 'setup.prompt.password_reset_new_password';
    public const SETUP_PROMPT_PASSWORD_RESET_CONFIRM_PASSWORD = 'setup.prompt.password_reset_confirm_password';
}
