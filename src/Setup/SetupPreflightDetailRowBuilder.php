<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupPreflightDetailRowBuilder
{
    public function __construct(
        private SetupPreflightCheckFactory $checkFactory = new SetupPreflightCheckFactory(),
        private SetupPreflightRequirementCatalog $requirementCatalog = new SetupPreflightRequirementCatalog(),
    ) {
    }

    /**
     * @param list<array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}> $checks
     *
     * @return list<array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}>
     */
    public function build(array $checks): array
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
                && in_array(substr($check['key'], strlen('extension_')), $this->requirementCatalog->databaseDriverExtensions(), true),
        ));
        $optionalMediaExtensions = array_values(array_filter(
            $checks,
            fn (array $check): bool => str_starts_with($check['key'], 'extension_')
                && false === $check['required']
                && in_array(substr($check['key'], strlen('extension_')), $this->requirementCatalog->mediaExtensions(), true),
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

        return $this->checkFactory->row(
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

        return $this->checkFactory->row(
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
}
