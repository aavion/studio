<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Process\CliProcessEnvironment;
use App\Core\Process\PhpCliBinaryManager;
use App\Core\Process\PhpCliBinaryResolver;
use App\Core\Process\PhpProjectRequirements;
use Symfony\Component\Process\Process;

final readonly class SetupPreflightChecker
{
    public function __construct(
        private PhpCliBinaryResolver $phpCliBinaryResolver = new PhpCliBinaryResolver(),
        private PhpCliBinaryManager $phpCliBinaryManager = new PhpCliBinaryManager(),
        private PhpProjectRequirements $phpRequirements = new PhpProjectRequirements(),
        private SetupComposerEnvironment $composerEnvironment = new SetupComposerEnvironment(),
    ) {
    }

    /**
     * @param array<string, mixed>|null $server
     *
     * @return array{ok: bool, has_warnings: bool, healable_failed: bool, can_auto_heal: bool, checks: list<array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}>, detail_rows: list<array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}>}
     */
    public function check(string $projectDir, string $environment, bool $autoHeal = false, ?array $server = null): array
    {
        $requiredExtensions = $this->phpRequirements->requiredPhpExtensions($projectDir);
        $optionalDatabaseExtensions = array_values(array_diff($this->databaseDriverExtensions(), $requiredExtensions));
        $optionalMediaExtensions = array_values(array_diff($this->mediaExtensions(), $requiredExtensions));
        $checks = [
            $this->webroot($projectDir, $server ?? $_SERVER),
            $this->phpVersion($projectDir),
            $this->safeMode(),
            $this->processFunctions(),
            $this->composerBinary($projectDir, $environment, $autoHeal),
            $this->tailwindBuild($projectDir),
            $this->directoryWritable($projectDir.'/var', 'var_writable', true, $autoHeal),
            $this->fileWritable($projectDir.'/.env.'.$environment.'.local', 'environment_writable', true, $autoHeal),
            $this->directoryWritable($projectDir.'/translations/runtime', 'runtime_translations_writable', true, $autoHeal),
            $this->directoryWritable($projectDir.'/public', 'public_writable', true, $autoHeal),
            $this->cliRunnerAvailable($projectDir, $environment, $autoHeal),
            ...array_map(fn (string $extension): array => $this->phpExtension($extension, true), $requiredExtensions),
            ...array_map(fn (string $extension): array => $this->phpExtension($extension, false), $optionalDatabaseExtensions),
            ...array_map(fn (string $extension): array => $this->phpExtension($extension, false), $optionalMediaExtensions),
        ];
        $failedRequired = array_filter($checks, static fn (array $check): bool => true === $check['required'] && 'ok' !== $check['status']);

        return [
            'ok' => [] === $failedRequired,
            'has_warnings' => [] !== array_filter($checks, static fn (array $check): bool => 'warning' === $check['status']),
            'healable_failed' => [] !== array_filter($failedRequired, static fn (array $check): bool => true === $check['healable']),
            'can_auto_heal' => [] !== $failedRequired && [] === array_filter($failedRequired, static fn (array $check): bool => true !== $check['healable']),
            'checks' => $checks,
            'detail_rows' => $this->detailRows($checks),
        ];
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
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

        return $this->checkRow($key, $writable ? 'ok' : 'failed', $required, $healable, $writable ? 'writable' : 'not_writable', [
            '%path%' => $this->shortPath($path),
        ]);
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
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

        return $this->checkRow($key, $writable ? 'ok' : 'failed', $required, $healable, $writable ? 'writable' : 'not_writable', [
            '%path%' => $this->shortPath($path),
        ]);
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    private function webroot(string $projectDir, array $server): array
    {
        $documentRoot = $server['DOCUMENT_ROOT'] ?? null;

        if (!is_string($documentRoot) || '' === trim($documentRoot)) {
            return $this->checkRow('webroot_public', 'ok', true, false, 'not_detected');
        }

        $actual = realpath($documentRoot);
        $expected = realpath($projectDir.'/public');

        return $this->checkRow('webroot_public', false !== $actual && false !== $expected && $actual === $expected ? 'ok' : 'failed', true, false, 'path', [
            '%path%' => $this->shortPath(false !== $actual ? $actual : $documentRoot),
        ]);
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    private function phpVersion(string $projectDir): array
    {
        $requiredVersion = $this->phpRequirements->minimumPhpVersion($projectDir);
        $status = null === $requiredVersion || version_compare(PHP_VERSION, $requiredVersion, '>=')
            ? 'ok'
            : 'failed';

        return $this->checkRow('php_version', $status, true, false, 'ok' === $status ? 'php_version' : 'php_version_requirement', [
            '%version%' => PHP_VERSION,
            '%required%' => $requiredVersion ?? '',
        ]);
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    private function safeMode(): array
    {
        $enabled = $this->phpCliBinaryResolver->safeModeEnabled();

        return $this->checkRow('safe_mode', $enabled ? 'failed' : 'ok', true, false, $enabled ? 'safe_mode_enabled' : 'safe_mode_disabled');
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    private function processFunctions(): array
    {
        $unavailable = $this->phpCliBinaryResolver->unavailableProcessFunctions();

        return $this->checkRow('process_functions', [] === $unavailable ? 'ok' : 'failed', true, false, [] === $unavailable ? 'process_functions_available' : 'process_functions_disabled', [
            '%functions%' => implode(', ', $unavailable),
        ]);
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    private function composerBinary(string $projectDir, string $environment, bool $autoHeal): array
    {
        $bundledComposer = $projectDir.'/bin/composer';
        $composerEnvironment = $this->composerEnvironment->create($projectDir);
        if ($autoHeal && is_file($bundledComposer) && !is_executable($bundledComposer) && is_writable($bundledComposer)) {
            @chmod($bundledComposer, 0755);
        }

        $phpCli = $this->phpCliBinaryManager->resolve($projectDir, $environment, $composerEnvironment, $autoHeal);
        $phpCommand = $phpCli->commandPrefix();

        if ($phpCli->isAvailable() && is_file($bundledComposer) && is_readable($bundledComposer) && $this->composerCommandWorks([...$phpCommand, $bundledComposer, '--version'], $projectDir, $composerEnvironment)) {
            return $this->checkRow('composer_binary', 'ok', true, false, 'composer_bundled');
        }

        if (is_file($bundledComposer)) {
            $works = $phpCli->isAvailable()
                && is_readable($bundledComposer)
                && $this->composerCommandWorks([...$phpCommand, $bundledComposer, '--version'], $projectDir, $composerEnvironment);
            if (!$works && $autoHeal && is_writable($bundledComposer) && $this->downloadBundledComposer($bundledComposer, $projectDir, $environment)) {
                return $this->checkRow('composer_binary', 'ok', true, false, 'composer_bundled');
            }

            if (!$works && $this->composerCommandWorks(['composer', '--version'], $projectDir, $composerEnvironment)) {
                return $this->checkRow('composer_binary', 'ok', true, false, 'composer_system');
            }

            return $this->checkRow(
                'composer_binary',
                $works ? 'ok' : 'failed',
                true,
                !$works && is_writable($bundledComposer) && $this->canDownloadBundledComposer($projectDir),
                $works ? 'composer_bundled' : ($phpCli->isAvailable() ? 'composer_not_executable' : $this->phpCliFailureValueKey($phpCli->reason())),
            );
        }

        if ($this->composerCommandWorks(['composer', '--version'], $projectDir, $composerEnvironment)) {
            return $this->checkRow('composer_binary', 'ok', true, false, 'composer_system');
        }

        if ($autoHeal && $this->canDownloadBundledComposer($projectDir) && $this->downloadBundledComposer($bundledComposer, $projectDir, $environment)) {
            return $this->checkRow('composer_binary', 'ok', true, false, 'composer_bundled');
        }

        return $this->checkRow('composer_binary', 'failed', true, $this->canDownloadBundledComposer($projectDir), 'unavailable');
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    private function phpExtension(string $extension, bool $required): array
    {
        return $this->checkRow('extension_'.$extension, extension_loaded($extension) ? 'ok' : 'missing', $required, false, extension_loaded($extension) ? 'present' : 'missing');
    }

    /**
     * @return list<string>
     */
    private function databaseDriverExtensions(): array
    {
        return [
            'pdo_sqlite',
            'pdo_mysql',
            'pdo_pgsql',
        ];
    }

    /**
     * @return list<string>
     */
    private function mediaExtensions(): array
    {
        return [
            'imagick',
        ];
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    private function cliRunnerAvailable(string $projectDir, string $environment, bool $autoHeal): array
    {
        $resolution = $this->phpCliBinaryManager->resolve($projectDir, $environment, persistPreference: $autoHeal);

        return $this->checkRow(
            'cli_runner',
            $resolution->isAvailable() ? 'ok' : 'failed',
            true,
            false,
            $resolution->isAvailable() ? 'executable' : $this->phpCliFailureValueKey($resolution->reason()),
        );
    }

    private function phpCliFailureValueKey(string $reason): string
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

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    private function tailwindBuild(string $projectDir): array
    {
        $binary = $this->tailwindBinary($projectDir);
        if (null === $binary) {
            return $this->checkRow('tailwind_build', 'warning', false, false, 'tailwind_not_prepared');
        }

        if ($this->tailwindSmokeBuildWorks($binary, $projectDir)) {
            return $this->checkRow('tailwind_build', 'ok', false, false, 'tailwind_available');
        }

        return $this->checkRow('tailwind_build', 'warning', false, false, 'tailwind_blocked');
    }

    /**
     * @param array<string, string> $valueParameters
     *
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    private function checkRow(string $key, string $status, bool $required, bool $healable, string $valueKey, array $valueParameters = []): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'required' => $required,
            'healable' => $healable,
            'label_key' => 'setup.preflight.checks.'.$key.'.label',
            'help_key' => 'setup.preflight.checks.'.$key.'.help',
            'instruction_key' => 'setup.preflight.checks.'.$key.'.instruction',
            'value_key' => 'setup.preflight.values.'.$valueKey,
            'value_parameters' => $valueParameters,
        ];
    }

    /**
     * @param list<array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}> $checks
     *
     * @return list<array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}>
     */
    private function detailRows(array $checks): array
    {
        $byKey = [];
        foreach ($checks as $check) {
            $byKey[$check['key']] = $check;
        }

        $requiredExtensions = array_values(array_filter(
            $checks,
            static fn (array $check): bool => str_starts_with($check['key'], 'extension_') && true === $check['required'],
        ));
        $optionalDatabaseExtensions = array_values(array_filter(
            $checks,
            fn (array $check): bool => str_starts_with($check['key'], 'extension_')
                && false === $check['required']
                && in_array(substr($check['key'], strlen('extension_')), $this->databaseDriverExtensions(), true),
        ));
        $optionalMediaExtensions = array_values(array_filter(
            $checks,
            fn (array $check): bool => str_starts_with($check['key'], 'extension_')
                && false === $check['required']
                && in_array(substr($check['key'], strlen('extension_')), $this->mediaExtensions(), true),
        ));
        $writablePaths = array_values(array_filter(
            $checks,
            static fn (array $check): bool => in_array($check['key'], ['var_writable', 'environment_writable', 'runtime_translations_writable', 'public_writable'], true),
        ));

        return array_values(array_filter([
            $byKey['webroot_public'] ?? null,
            $byKey['php_version'] ?? null,
            $byKey['safe_mode'] ?? null,
            $byKey['process_functions'] ?? null,
            $this->extensionSummary('required_extensions', $requiredExtensions, true),
            $byKey['cli_runner'] ?? null,
            $byKey['composer_binary'] ?? null,
            $byKey['tailwind_build'] ?? null,
            $this->writablePathSummary($writablePaths),
            $this->extensionSummary('optional_database_extensions', $optionalDatabaseExtensions, false),
            $this->extensionSummary('optional_media_extensions', $optionalMediaExtensions, false),
        ]));
    }

    /**
     * @param list<array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}> $checks
     *
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    private function extensionSummary(string $key, array $checks, bool $required): array
    {
        $missing = array_values(array_map(
            static fn (array $check): string => substr($check['key'], strlen('extension_')),
            array_filter($checks, static fn (array $check): bool => 'ok' !== $check['status']),
        ));

        return $this->checkRow(
            $key,
            [] === $missing ? 'ok' : 'missing',
            $required,
            false,
            [] === $missing ? ($required ? 'extensions_all_present' : 'optional_extensions_all_present') : 'extensions_missing',
            [
                '%count%' => (string) count($checks),
                '%extensions%' => implode(', ', $missing),
            ],
        );
    }

    /**
     * @param list<array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}> $checks
     *
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    private function writablePathSummary(array $checks): array
    {
        $failed = array_values(array_filter($checks, static fn (array $check): bool => 'ok' !== $check['status']));

        return $this->checkRow(
            'writable_paths',
            [] === $failed ? 'ok' : 'failed',
            true,
            [] !== $failed && [] === array_filter($failed, static fn (array $check): bool => true !== $check['healable']),
            [] === $failed ? 'writable_paths_ok' : (
                [] === array_filter($failed, static fn (array $check): bool => true !== $check['healable'])
                    ? 'writable_paths_unavailable'
                    : 'writable_paths_blocked'
            ),
            [
                '%count%' => (string) count($checks),
                '%paths_count%' => (string) count($failed),
                '%paths%' => implode(', ', array_map(
                    static fn (array $check): string => $check['value_parameters']['%path%'] ?? $check['key'],
                    $failed,
                )),
            ],
        );
    }

    private function shortPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $parts = array_values(array_filter(explode('/', $normalized), static fn (string $part): bool => '' !== $part));

        if (count($parts) <= 4) {
            return $path;
        }

        return '/'.implode('/', array_slice($parts, 0, 2)).'/.../'.implode('/', array_slice($parts, -2));
    }

    private function firstExistingParent(string $path): string
    {
        $parent = dirname($path);

        while (!is_dir($parent) && $parent !== dirname($parent)) {
            $parent = dirname($parent);
        }

        return $parent;
    }

    /**
     * @param list<string> $command
     */
    private function commandWorks(array $command, ?string $workingDirectory): bool
    {
        try {
            $process = new Process($command, $workingDirectory, CliProcessEnvironment::fromCurrentProcess($this->processEnvironment()), timeout: 5.0);
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful();
    }

    private function canDownloadBundledComposer(string $projectDir): bool
    {
        return is_dir($projectDir.'/bin')
            && is_writable($projectDir.'/bin')
            && $this->commandWorks(['curl', '--version'], $projectDir);
    }

    private function downloadBundledComposer(string $target, string $projectDir, string $environment): bool
    {
        $temporary = $target.'.tmp-'.bin2hex(random_bytes(4));

        try {
            $process = new Process([
                'curl',
                '-fsSL',
                'https://getcomposer.org/download/latest-stable/composer.phar',
                '-o',
                $temporary,
            ], $projectDir, CliProcessEnvironment::fromCurrentProcess($this->processEnvironment()), timeout: 30.0);
            $process->run();

            if (!$process->isSuccessful() || !is_file($temporary)) {
                @unlink($temporary);

                return false;
            }

            @chmod($temporary, 0755);

            if (!@rename($temporary, $target)) {
                @unlink($temporary);

                return false;
            }

            $composerEnvironment = $this->composerEnvironment->create($projectDir);
            $phpCli = $this->phpCliBinaryManager->resolve($projectDir, $environment, $composerEnvironment, true);

            return is_executable($target)
                && $phpCli->isAvailable()
                && $this->composerCommandWorks([...$phpCli->commandPrefix(), $target, '--version'], $projectDir, $composerEnvironment);
        } catch (\Throwable) {
            @unlink($temporary);

            return false;
        }
    }

    /**
     * @param list<string> $command
     */
    private function composerCommandWorks(array $command, ?string $workingDirectory, array $environment): bool
    {
        try {
            $process = new Process($command, $workingDirectory, CliProcessEnvironment::fromCurrentProcess($environment), timeout: 5.0);
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful()
            && str_contains($process->getOutput().$process->getErrorOutput(), 'Composer');
    }

    /**
     * @return array<string, string>
     */
    private function processEnvironment(): array
    {
        $path = $_SERVER['PATH'] ?? $_ENV['PATH'] ?? getenv('PATH');

        return is_string($path) && '' !== $path ? ['PATH' => $path] : [];
    }

    private function tailwindBinary(string $projectDir): ?string
    {
        $candidates = glob($projectDir.'/var/tailwind/*/tailwindcss-*') ?: [];
        $candidates = array_values(array_filter(
            $candidates,
            static fn (string $candidate): bool => is_file($candidate) && is_executable($candidate),
        ));
        rsort($candidates);

        return $candidates[0] ?? null;
    }

    private function tailwindSmokeBuildWorks(string $binary, string $projectDir): bool
    {
        $temporaryDirectory = sys_get_temp_dir().'/studio_tailwind_preflight_'.bin2hex(random_bytes(4));

        if (!@mkdir($temporaryDirectory, 0775, true) && !is_dir($temporaryDirectory)) {
            return false;
        }

        $input = $temporaryDirectory.'/input.css';
        $output = $temporaryDirectory.'/output.css';
        @file_put_contents($input, ".studio-tailwind-preflight{color:red}\n");

        try {
            $process = new Process(
                [$binary, '-i', $input, '-o', $output],
                $projectDir,
                CliProcessEnvironment::fromCurrentProcess($this->processEnvironment()),
                timeout: 10.0,
            );
            $process->run();

            return $process->isSuccessful() && is_file($output);
        } catch (\Throwable) {
            return false;
        } finally {
            @unlink($input);
            @unlink($output);
            @rmdir($temporaryDirectory);
        }
    }
}
