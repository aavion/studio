<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Process\PhpCliBinaryManager;
use App\Core\Process\PhpCliBinaryResolver;
use App\Core\Process\PhpProjectRequirements;

final readonly class SetupPreflightChecker
{
    public function __construct(
        private PhpCliBinaryResolver $phpCliBinaryResolver = new PhpCliBinaryResolver(),
        private PhpCliBinaryManager $phpCliBinaryManager = new PhpCliBinaryManager(),
        private PhpProjectRequirements $phpRequirements = new PhpProjectRequirements(),
        private SetupPreflightCheckFactory $checkFactory = new SetupPreflightCheckFactory(),
        private SetupPreflightRequirementCatalog $requirementCatalog = new SetupPreflightRequirementCatalog(),
        private SetupPreflightDetailRowBuilder $detailRowBuilder = new SetupPreflightDetailRowBuilder(),
        private SetupComposerPreflightProbe $composerProbe = new SetupComposerPreflightProbe(),
        private SetupTailwindPreflightProbe $tailwindProbe = new SetupTailwindPreflightProbe(),
        private SetupPreflightPhpCliFailureMapper $phpCliFailureMapper = new SetupPreflightPhpCliFailureMapper(),
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
        $optionalDatabaseExtensions = array_values(array_diff($this->requirementCatalog->databaseDriverExtensions(), $requiredExtensions));
        $optionalMediaExtensions = array_values(array_diff($this->requirementCatalog->mediaExtensions(), $requiredExtensions));
        $checks = [
            $this->webroot($projectDir, $server ?? $_SERVER),
            $this->phpVersion($projectDir),
            $this->safeMode(),
            $this->processFunctions(),
            $this->composerProbe->check($projectDir, $environment, $autoHeal),
            $this->tailwindProbe->check($projectDir),
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
            'detail_rows' => $this->detailRowBuilder->build($checks),
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

        return $this->checkFactory->row($key, $writable ? 'ok' : 'failed', $required, $healable, $writable ? 'writable' : 'not_writable', [
            '%path%' => $this->checkFactory->shortPath($path),
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

        return $this->checkFactory->row($key, $writable ? 'ok' : 'failed', $required, $healable, $writable ? 'writable' : 'not_writable', [
            '%path%' => $this->checkFactory->shortPath($path),
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
            return $this->checkFactory->row('webroot_public', 'ok', true, false, 'not_detected');
        }

        $actual = realpath($documentRoot);
        $expected = realpath($projectDir.'/public');

        return $this->checkFactory->row('webroot_public', false !== $actual && false !== $expected && $actual === $expected ? 'ok' : 'failed', true, false, 'path', [
            '%path%' => $this->checkFactory->shortPath(false !== $actual ? $actual : $documentRoot),
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

        return $this->checkFactory->row('php_version', $status, true, false, 'ok' === $status ? 'php_version' : 'php_version_requirement', [
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

        return $this->checkFactory->row('safe_mode', $enabled ? 'failed' : 'ok', true, false, $enabled ? 'safe_mode_enabled' : 'safe_mode_disabled');
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    private function processFunctions(): array
    {
        $unavailable = $this->phpCliBinaryResolver->unavailableProcessFunctions();

        return $this->checkFactory->row('process_functions', [] === $unavailable ? 'ok' : 'failed', true, false, [] === $unavailable ? 'process_functions_available' : 'process_functions_disabled', [
            '%functions%' => implode(', ', $unavailable),
        ]);
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    private function phpExtension(string $extension, bool $required): array
    {
        return $this->checkFactory->row('extension_'.$extension, extension_loaded($extension) ? 'ok' : 'missing', $required, false, extension_loaded($extension) ? 'present' : 'missing');
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    private function cliRunnerAvailable(string $projectDir, string $environment, bool $autoHeal): array
    {
        $resolution = $this->phpCliBinaryManager->resolve($projectDir, $environment, persistPreference: $autoHeal);

        return $this->checkFactory->row(
            'cli_runner',
            $resolution->isAvailable() ? 'ok' : 'failed',
            true,
            false,
            $resolution->isAvailable() ? 'executable' : $this->phpCliFailureMapper->valueKey($resolution->reason()),
        );
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
