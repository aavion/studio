<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupPreflightChecker
{
    /**
     * @return array{ok: bool, checks: list<array{key: string, status: string, required: bool, label_key: string, help_key: string}>}
     */
    public function check(string $projectDir, string $environment): array
    {
        $checks = [
            $this->directoryWritable($projectDir.'/var', 'var_writable', true),
            $this->fileWritable($projectDir.'/.env.'.$environment.'.local', 'environment_writable', true),
            $this->directoryWritable($projectDir.'/translations/runtime', 'runtime_translations_writable', true),
            $this->directoryWritable($projectDir.'/public', 'public_writable', true),
            $this->phpExtension('ctype', true),
            $this->phpExtension('fileinfo', true),
            $this->phpExtension('iconv', true),
            $this->phpExtension('intl', true),
            $this->phpExtension('sqlite3', true),
            $this->phpExtension('pdo_mysql', false),
            $this->phpExtension('pdo_pgsql', false),
        ];

        return [
            'ok' => [] === array_filter($checks, static fn (array $check): bool => true === $check['required'] && 'ok' !== $check['status']),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{key: string, status: string, required: bool, label_key: string, help_key: string}
     */
    private function directoryWritable(string $path, string $key, bool $required): array
    {
        if (!is_dir($path) && is_writable(dirname($path))) {
            @mkdir($path, 0775, true);
        }

        $exists = is_dir($path);
        $writable = $exists ? is_writable($path) : is_writable(dirname($path));

        return $this->checkRow($key, $writable ? 'ok' : 'failed', $required);
    }

    /**
     * @return array{key: string, status: string, required: bool, label_key: string, help_key: string}
     */
    private function fileWritable(string $path, string $key, bool $required): array
    {
        $writable = is_file($path) ? is_writable($path) : is_writable(dirname($path));

        return $this->checkRow($key, $writable ? 'ok' : 'failed', $required);
    }

    /**
     * @return array{key: string, status: string, required: bool, label_key: string, help_key: string}
     */
    private function phpExtension(string $extension, bool $required): array
    {
        return $this->checkRow('extension_'.$extension, extension_loaded($extension) ? 'ok' : 'missing', $required);
    }

    /**
     * @return array{key: string, status: string, required: bool, label_key: string, help_key: string}
     */
    private function checkRow(string $key, string $status, bool $required): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'required' => $required,
            'label_key' => 'setup.preflight.checks.'.$key.'.label',
            'help_key' => 'setup.preflight.checks.'.$key.'.help',
        ];
    }
}
