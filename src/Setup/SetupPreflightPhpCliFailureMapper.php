<?php

declare(strict_types=1);

namespace App\Setup;

final class SetupPreflightPhpCliFailureMapper
{
    public function valueKey(string $reason): string
    {
        if (str_starts_with($reason, 'extension_missing:')) {
            return 'extension_missing';
        }

        return match ($reason) {
            'binary_not_found',
            'console_unreadable',
            'extension_missing',
            'not_cli',
            'php_version_too_old',
            'process_disabled',
            'process_failed',
            'project_dir_unreadable',
            'safe_mode_enabled',
            'server_config',
            'validation_failed' => $reason,
            default => 'validation_failed',
        };
    }
}
