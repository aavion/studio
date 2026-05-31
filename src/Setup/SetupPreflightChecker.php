<?php

declare(strict_types=1);

namespace App\Setup;

use Symfony\Component\Process\Process;

final readonly class SetupPreflightChecker
{
    /**
     * @param array<string, mixed>|null $server
     *
     * @return array{ok: bool, healable_failed: bool, checks: list<array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string}>}
     */
    public function check(string $projectDir, string $environment, bool $autoHeal = false, ?array $server = null): array
    {
        $checks = [
            $this->webroot($projectDir, $server ?? $_SERVER),
            $this->directoryWritable($projectDir.'/var', 'var_writable', true, $autoHeal),
            $this->fileWritable($projectDir.'/.env.'.$environment.'.local', 'environment_writable', true, $autoHeal),
            $this->directoryWritable($projectDir.'/translations/runtime', 'runtime_translations_writable', true, $autoHeal),
            $this->directoryWritable($projectDir.'/public', 'public_writable', true, $autoHeal),
            $this->cliRunnerAvailable(),
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
            'healable_failed' => [] !== array_filter($checks, static fn (array $check): bool => true === $check['required'] && true === $check['healable'] && 'ok' !== $check['status']),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string}
     */
    private function directoryWritable(string $path, string $key, bool $required, bool $autoHeal): array
    {
        $parent = $this->firstExistingParent($path);

        if ($autoHeal && !is_dir($path) && is_writable($parent)) {
            @mkdir($path, 0775, true);
        }

        $exists = is_dir($path);
        $writable = $exists && is_writable($path);
        $healable = !$exists && is_writable($parent);

        return $this->checkRow($key, $writable ? 'ok' : 'failed', $required, $healable);
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string}
     */
    private function fileWritable(string $path, string $key, bool $required, bool $autoHeal): array
    {
        $parent = $this->firstExistingParent($path);

        if ($autoHeal && !is_file($path) && is_writable($parent)) {
            if (!is_dir(dirname($path))) {
                @mkdir(dirname($path), 0775, true);
            }
            @touch($path);
        }

        $writable = is_file($path) ? is_writable($path) : is_writable($parent);
        $healable = !is_file($path) && is_writable($parent);

        return $this->checkRow($key, $writable ? 'ok' : 'failed', $required, $healable);
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string}
     */
    private function webroot(string $projectDir, array $server): array
    {
        $documentRoot = $server['DOCUMENT_ROOT'] ?? null;

        if (!is_string($documentRoot) || '' === trim($documentRoot)) {
            return $this->checkRow('webroot_public', 'ok', true, false);
        }

        $actual = realpath($documentRoot);
        $expected = realpath($projectDir.'/public');

        return $this->checkRow('webroot_public', false !== $actual && false !== $expected && $actual === $expected ? 'ok' : 'failed', true, false);
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string}
     */
    private function phpExtension(string $extension, bool $required): array
    {
        return $this->checkRow('extension_'.$extension, extension_loaded($extension) ? 'ok' : 'missing', $required, false);
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string}
     */
    private function cliRunnerAvailable(): array
    {
        try {
            $process = new Process([PHP_BINARY, '-r', 'exit(0);'], timeout: 5.0);
            $process->run();
        } catch (\Throwable) {
            return $this->checkRow('cli_runner', 'failed', true, false);
        }

        return $this->checkRow('cli_runner', $process->isSuccessful() ? 'ok' : 'failed', true, false);
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string}
     */
    private function checkRow(string $key, string $status, bool $required, bool $healable): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'required' => $required,
            'healable' => $healable,
            'label_key' => 'setup.preflight.checks.'.$key.'.label',
            'help_key' => 'setup.preflight.checks.'.$key.'.help',
            'instruction_key' => 'setup.preflight.checks.'.$key.'.instruction',
        ];
    }

    private function firstExistingParent(string $path): string
    {
        $parent = dirname($path);

        while (!is_dir($parent) && $parent !== dirname($parent)) {
            $parent = dirname($parent);
        }

        return $parent;
    }
}
