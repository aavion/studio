<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Manifest\Manifest;

final class ExtensionManifestVariables
{
    /**
     * @return array<string, array{key: string, source_key: string, value: mixed, type: string}>
     */
    public function fromManifest(string $extensionName, Manifest $manifest): array
    {
        $namespace = 'ext.'.str_replace('-', '_', $extensionName);
        $variables = [];

        foreach ($manifest->all() as $sourceKey => $rawValue) {
            if (!str_starts_with($sourceKey, 'EXTENSION_')) {
                continue;
            }

            $name = strtolower(substr($sourceKey, strlen('EXTENSION_')));
            if ('' === $name) {
                continue;
            }

            $key = $namespace.'.'.$name;
            $typed = $this->typedValue($rawValue);
            $variables[$key] = [
                'key' => $key,
                'source_key' => $sourceKey,
                'value' => $typed['value'],
                'type' => $typed['type'],
            ];
        }

        ksort($variables);

        return $variables;
    }

    /**
     * @return array{value: mixed, type: string}
     */
    private function typedValue(string $rawValue): array
    {
        $value = trim($rawValue);
        $lower = strtolower($value);

        if ('true' === $lower || 'false' === $lower) {
            return ['value' => 'true' === $lower, 'type' => 'boolean'];
        }

        if (1 === preg_match('/^[+-]?\d+$/', $value)) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            return false === $integer
                ? ['value' => $value, 'type' => 'bigint']
                : ['value' => $integer, 'type' => 'integer'];
        }

        if (
            1 === preg_match('/^[+-]?(?:(?:\d+\.\d*)|(?:\.\d+)|(?:\d+))(?:[eE][+-]?\d+)?$/', $value)
            && (str_contains($value, '.') || str_contains($value, 'e') || str_contains($value, 'E'))
        ) {
            return ['value' => (float) $value, 'type' => 'float'];
        }

        if (str_starts_with($value, '[') || str_starts_with($value, '{')) {
            $decoded = json_decode($value, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                return ['value' => $decoded, 'type' => 'array'];
            }

            $list = $this->simpleList($value);
            if (null !== $list) {
                return ['value' => $list, 'type' => 'array'];
            }
        }

        return ['value' => $rawValue, 'type' => 'string'];
    }

    /**
     * @return list<string>|null
     */
    private function simpleList(string $value): ?array
    {
        if (!str_starts_with($value, '[') || !str_ends_with($value, ']')) {
            return null;
        }

        $inner = trim(substr($value, 1, -1));
        if ('' === $inner || str_contains($inner, '[') || str_contains($inner, ']') || str_contains($inner, '"') || str_contains($inner, "'")) {
            return null;
        }

        $items = array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $inner),
        ), static fn (string $item): bool => '' !== $item));

        return [] === $items ? null : $items;
    }
}
