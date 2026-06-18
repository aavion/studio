<?php

declare(strict_types=1);

namespace App\Security\Abuse;

enum RequestIntent: string
{
    case BrowserNavigation = 'browser_navigation';
    case TurboPrefetch = 'turbo_prefetch';
    case FormSubmit = 'form_submit';
    case ApiRead = 'api_read';
    case ApiWrite = 'api_write';
    case CorsPreflight = 'cors_preflight';
    case LiveApi = 'live_api';
    case SchedulerTrigger = 'scheduler_trigger';
    case CaptchaRefresh = 'captcha_refresh';
    case CaptchaFailure = 'captcha_failure';
    case Login = 'login';
    case RecoveryLogin = 'recovery_login';
    case Registration = 'registration';
    case PasswordReset = 'password_reset';
    case Contact = 'contact';
    case SetupApply = 'setup_apply';
    case PackageAdminOperation = 'package_admin_operation';
    case SettingsMutation = 'settings_mutation';
    case UserAclMutation = 'user_acl_mutation';
    case UploadArchiveValidation = 'upload_archive_validation';
    case ExportDownload = 'export_download';
    case ImportOperation = 'import_operation';
    case BackupRestore = 'backup_restore';
    case DiagnosticsSupport = 'diagnostics_support';
    case AdminOperation = 'admin_operation';
    case SuspiciousProbe = 'suspicious_probe';
    case Unknown = 'unknown';
}
