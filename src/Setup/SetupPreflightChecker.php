<?php

declare(strict_types=1);

namespace App\Setup;

use Symfony\Component\Process\Process;

final readonly class SetupPreflightChecker
{
    /**
     * @param array<string, mixed>|null $server
     *
     * @return array{ok: bool, healable_failed: bool, can_auto_heal: bool, checks: list<array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}>, detail_rows: list<array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}>}
     */
    public function check(string $projectDir, string $environment, bool $autoHeal = false, ?array $server = null): array
    {
        $requiredExtensions = $this->requiredPhpExtensions($projectDir);
        $optionalExtensions = array_values(array_diff($this->databaseDriverExtensions(), $requiredExtensions));
        $checks = [
            $this->webroot($projectDir, $server ?? $_SERVER),
            $this->phpVersion($projectDir),
            $this->composerBinary($projectDir, $autoHeal),
            $this->directoryWritable($projectDir.'/var', 'var_writable', true, $autoHeal),
            $this->fileWritable($projectDir.'/.env.'.$environment.'.local', 'environment_writable', true, $autoHeal),
            $this->directoryWritable($projectDir.'/translations/runtime', 'runtime_translations_writable', true, $autoHeal),
            $this->directoryWritable($projectDir.'/public', 'public_writable', true, $autoHeal),
            $this->cliRunnerAvailable(),
            ...array_map(fn (string $extension): array => $this->phpExtension($extension, true), $requiredExtensions),
            ...array_map(fn (string $extension): array => $this->phpExtension($extension, false), $optionalExtensions),
        ];
        $failedRequired = array_filter($checks, static fn (array $check): bool => true === $check['required'] && 'ok' !== $check['status']);

        return [
            'ok' => [] === $failedRequired,
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
        $requiredVersion = $this->minimumPhpVersion($projectDir);
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
    private function composerBinary(string $projectDir, bool $autoHeal): array
    {
        $bundledComposer = $projectDir.'/bin/composer';
        if ($autoHeal && is_file($bundledComposer) && !is_executable($bundledComposer) && is_writable($bundledComposer)) {
            @chmod($bundledComposer, 0755);
        }

        if (is_file($bundledComposer) && is_readable($bundledComposer) && $this->commandWorks([PHP_BINARY, $bundledComposer, '--version'], $projectDir)) {
            return $this->checkRow('composer_binary', 'ok', true, false, 'composer_bundled');
        }

        if ($this->commandWorks(['composer', '--version'], $projectDir)) {
            return $this->checkRow('composer_binary', 'ok', true, false, 'composer_system');
        }

        if (is_file($bundledComposer)) {
            $works = is_readable($bundledComposer) && $this->commandWorks([PHP_BINARY, $bundledComposer, '--version'], $projectDir);

            return $this->checkRow('composer_binary', $works ? 'ok' : 'failed', true, !$works && is_writable($bundledComposer), $works ? 'composer_bundled' : 'composer_not_executable');
        }

        if ($autoHeal && $this->canDownloadBundledComposer($projectDir) && $this->downloadBundledComposer($bundledComposer, $projectDir)) {
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
    private function requiredPhpExtensions(string $projectDir): array
    {
        $composer = $this->readJsonFile($projectDir.'/composer.json');
        $required = [];

        foreach ($this->phpExtensionRequirements($composer['require'] ?? []) as $extension) {
            $required[] = $extension;
        }

        $lock = $this->readJsonFile($projectDir.'/composer.lock');
        foreach (($lock['packages'] ?? []) as $package) {
            if (is_array($package)) {
                foreach ($this->phpExtensionRequirements($package['require'] ?? []) as $extension) {
                    $required[] = $extension;
                }
            }
        }

        sort($required);

        return array_values(array_unique($required));
    }

    /**
     * @param mixed $requirements
     *
     * @return list<string>
     */
    private function phpExtensionRequirements(mixed $requirements): array
    {
        if (!is_array($requirements)) {
            return [];
        }

        $extensions = [];
        foreach ($requirements as $name => $constraint) {
            if (is_string($name) && str_starts_with($name, 'ext-')) {
                $extensions[] = substr($name, strlen('ext-'));
            }
        }

        return $extensions;
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
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    private function cliRunnerAvailable(): array
    {
        $available = $this->commandWorks([PHP_BINARY, '-r', 'exit(0);'], null);

        return $this->checkRow('cli_runner', $available ? 'ok' : 'failed', true, false, $available ? 'executable' : 'unavailable');
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
        $optionalExtensions = array_values(array_filter(
            $checks,
            static fn (array $check): bool => str_starts_with($check['key'], 'extension_') && false === $check['required'],
        ));
        $writablePaths = array_values(array_filter(
            $checks,
            static fn (array $check): bool => in_array($check['key'], ['var_writable', 'environment_writable', 'runtime_translations_writable', 'public_writable'], true),
        ));

        return array_values(array_filter([
            $byKey['webroot_public'] ?? null,
            $byKey['php_version'] ?? null,
            $this->extensionSummary('required_extensions', $requiredExtensions, true),
            $byKey['cli_runner'] ?? null,
            $byKey['composer_binary'] ?? null,
            $this->writablePathSummary($writablePaths),
            $this->extensionSummary('optional_database_extensions', $optionalExtensions, false),
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
            $process = new Process($command, $workingDirectory, timeout: 5.0);
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

    private function downloadBundledComposer(string $target, string $projectDir): bool
    {
        $temporary = $target.'.tmp-'.bin2hex(random_bytes(4));

        try {
            $process = new Process([
                'curl',
                '-fsSL',
                'https://getcomposer.org/download/latest-stable/composer.phar',
                '-o',
                $temporary,
            ], $projectDir, timeout: 30.0);
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

            return is_executable($target) && $this->commandWorks([PHP_BINARY, $target, '--version'], $projectDir);
        } catch (\Throwable) {
            @unlink($temporary);

            return false;
        }
    }

    private function minimumPhpVersion(string $projectDir): ?string
    {
        $versions = [];

        $composer = $this->readJsonFile($projectDir.'/composer.json');
        if (null !== $composer) {
            $versions[] = $composer['require']['php'] ?? null;
        }

        $lock = $this->readJsonFile($projectDir.'/composer.lock');
        if (null !== $lock) {
            $versions[] = $lock['platform']['php'] ?? null;

            foreach (['packages', 'packages-dev'] as $section) {
                foreach (($lock[$section] ?? []) as $package) {
                    if (is_array($package)) {
                        $versions[] = $package['require']['php'] ?? null;
                    }
                }
            }
        }

        $minimum = null;
        foreach ($versions as $constraint) {
            if (!is_string($constraint)) {
                continue;
            }

            $candidate = $this->minimumVersionFromConstraint($constraint);
            if (null !== $candidate && (null === $minimum || version_compare($candidate, $minimum, '>'))) {
                $minimum = $candidate;
            }
        }

        return $minimum;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonFile(string $path): ?array
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function minimumVersionFromConstraint(string $constraint): ?string
    {
        $alternatives = preg_split('/\s*\|\|?\s*/', $constraint) ?: [$constraint];
        $minimum = null;

        foreach ($alternatives as $alternative) {
            $candidate = $this->minimumVersionFromConstraintAlternative($alternative);
            if (null !== $candidate && (null === $minimum || version_compare($candidate, $minimum, '<'))) {
                $minimum = $candidate;
            }
        }

        return $minimum;
    }

    private function minimumVersionFromConstraintAlternative(string $constraint): ?string
    {
        $minimum = null;
        if (preg_match_all('/(?:>=|\^|~)\s*v?([0-9]+(?:\.[0-9]+){1,2})/', $constraint, $matches)) {
            foreach ($matches[1] as $version) {
                $version = $this->normalizeVersion($version);
                if (null === $minimum || version_compare($version, $minimum, '>')) {
                    $minimum = $version;
                }
            }
        }

        if (null === $minimum && preg_match('/^\s*v?([0-9]+(?:\.[0-9]+){1,2})(?:\s|$|-)/', $constraint, $match)) {
            $minimum = $this->normalizeVersion($match[1]);
        }

        return $minimum;
    }

    private function normalizeVersion(string $version): string
    {
        $parts = explode('.', $version);
        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_slice($parts, 0, 3));
    }
}
