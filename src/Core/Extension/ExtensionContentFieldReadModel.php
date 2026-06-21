<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Validation\IdentifierSpec;
use App\Entity\ContentFieldValue;
use App\Entity\ContentRevision;

final readonly class ExtensionContentFieldReadModel
{
    private const MAX_FIELD_CONTEXTS = 100;
    private const MAX_FIELDS_PER_CONTEXT = 100;
    private const MAX_ARRAY_ITEMS = 500;
    private const MAX_STRING_LENGTH = 65536;
    private const MAX_DEPTH = 6;

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function options(array $options, bool $includeFieldsByDefault): array
    {
        return [
            ...$options,
            'include_fields' => $this->boolOption($options['include_fields'] ?? null, $includeFieldsByDefault),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function revision(?ContentRevision $revision, array $options): ?array
    {
        if (!$revision instanceof ContentRevision) {
            return null;
        }

        return [
            'uid' => $revision->uid(),
            'version' => $revision->version(),
            'schema_uid' => $revision->schema()->uid(),
            'schema_identifier' => $revision->schema()->identifier(),
            'schema_version_uid' => $revision->schemaVersion()->uid(),
            'schema_version' => $revision->schemaVersion()->version(),
            'field_sets' => $this->fieldSets($revision, $this->fieldFilter($options)),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function fields(array $fields, array $options): array
    {
        return $this->portableFieldMap($fields, $this->fieldFilter($options));
    }

    /**
     * @param list<array{language: string, variant: string, fields: array<string, mixed>}> $fieldSets
     * @param array<string, mixed> $options
     * @return array{language: string, variant: string, fields: array<string, mixed>}|null
     */
    public function selectedFields(array $fieldSets, array $options): ?array
    {
        $language = is_string($options['language'] ?? null) ? trim((string) $options['language']) : '';
        $variant = is_string($options['variant'] ?? null) ? trim((string) $options['variant']) : 'default';
        $variant = '' !== $variant ? $variant : 'default';

        foreach ($fieldSets as $fieldSet) {
            if ($fieldSet['variant'] === $variant && ('' === $language || $fieldSet['language'] === $language)) {
                return $fieldSet;
            }
        }

        return $fieldSets[0] ?? null;
    }

    /**
     * @param array<string, true>|null $fieldFilter
     * @return list<array{language: string, variant: string, fields: array<string, mixed>}>
     */
    private function fieldSets(ContentRevision $revision, ?array $fieldFilter): array
    {
        $sets = [];

        foreach ($revision->fieldValues() as $fieldValue) {
            if (!$fieldValue instanceof ContentFieldValue) {
                continue;
            }

            $fieldIdentifier = $fieldValue->fieldIdentifier();
            if (null !== $fieldFilter && !isset($fieldFilter[$fieldIdentifier])) {
                continue;
            }

            $key = $fieldValue->language()."\0".$fieldValue->variant();
            if (!isset($sets[$key])) {
                if (count($sets) >= self::MAX_FIELD_CONTEXTS) {
                    continue;
                }

                $sets[$key] = [
                    'language' => $fieldValue->language(),
                    'variant' => $fieldValue->variant(),
                    'fields' => [],
                ];
            }

            if (count($sets[$key]['fields']) >= self::MAX_FIELDS_PER_CONTEXT) {
                continue;
            }

            $sets[$key]['fields'][$fieldIdentifier] = $this->portableValue($fieldValue->fieldContent());
        }

        ksort($sets);

        return array_values($sets);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, true>|null $fieldFilter
     * @return array<string, mixed>
     */
    private function portableFieldMap(array $fields, ?array $fieldFilter): array
    {
        $safe = [];
        foreach ($fields as $field => $value) {
            if (!is_string($field) || !IdentifierSpec::isSnakeIdentifier($field)) {
                continue;
            }

            if (null !== $fieldFilter && !isset($fieldFilter[$field])) {
                continue;
            }

            if (count($safe) >= self::MAX_FIELDS_PER_CONTEXT) {
                break;
            }

            $safe[$field] = $this->portableValue($value);
        }

        return $safe;
    }

    private function portableValue(mixed $value, int $depth = 0): mixed
    {
        if (null === $value || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return strlen($value) > self::MAX_STRING_LENGTH
                ? substr($value, 0, self::MAX_STRING_LENGTH)
                : $value;
        }

        if (!is_array($value) || $depth >= self::MAX_DEPTH) {
            return null;
        }

        $safe = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if (++$count > self::MAX_ARRAY_ITEMS) {
                break;
            }

            if (!is_int($key) && !is_string($key)) {
                continue;
            }

            $safe[$key] = $this->portableValue($item, $depth + 1);
        }

        return $safe;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, true>|null
     */
    private function fieldFilter(array $options): ?array
    {
        $fields = $options['fields'] ?? null;
        if (is_string($fields)) {
            $fields = array_map('trim', explode(',', $fields));
        }

        if (!is_array($fields)) {
            return null;
        }

        $safe = [];
        foreach ($fields as $field) {
            if (!is_string($field) || !IdentifierSpec::isSnakeIdentifier($field)) {
                continue;
            }

            $safe[$field] = true;
        }

        return [] !== $safe ? $safe : null;
    }

    private function boolOption(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return match (strtolower($value)) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => $default,
            };
        }

        return $default;
    }
}
