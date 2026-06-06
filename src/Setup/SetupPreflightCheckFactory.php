<?php

declare(strict_types=1);

namespace App\Setup;

final class SetupPreflightCheckFactory
{
    /**
     * @param array<string, string> $valueParameters
     *
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    public function row(string $key, string $status, bool $required, bool $healable, string $valueKey, array $valueParameters = []): array
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

    public function shortPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $parts = array_values(array_filter(explode('/', $normalized), static fn (string $part): bool => '' !== $part));

        if (count($parts) <= 4) {
            return $path;
        }

        return '/'.implode('/', array_slice($parts, 0, 2)).'/.../'.implode('/', array_slice($parts, -2));
    }
}
