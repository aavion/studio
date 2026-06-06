<?php

declare(strict_types=1);

namespace App\Form;

use App\Core\Config\ConfigValueType;
use JsonException;

final readonly class FormValueCaster
{
    /**
     * @return array{0: mixed, 1: list<string>}
     */
    public function cast(FormFieldDefinition $field, mixed $raw): array
    {
        return match ($field->valueType()) {
            ConfigValueType::String => is_scalar($raw)
                ? [trim((string) $raw), []]
                : [$field->defaultValue(), [FormErrorKey::INVALID]],
            ConfigValueType::Integer => $this->integerValue($field, $raw),
            ConfigValueType::Float => $this->floatValue($field, $raw),
            ConfigValueType::Json => $this->jsonValue($field, $raw),
            ConfigValueType::Boolean => [$this->booleanValue($raw), []],
        };
    }

    public function booleanValue(mixed $raw): bool
    {
        if (null === $raw || false === $raw) {
            return false;
        }

        if (is_string($raw)) {
            return !in_array(strtolower($raw), ['', '0', 'false', 'off', 'no'], true);
        }

        return true;
    }

    /**
     * @return array{0: mixed, 1: list<string>}
     */
    private function integerValue(FormFieldDefinition $field, mixed $raw): array
    {
        if (!is_scalar($raw) || false === filter_var((string) $raw, FILTER_VALIDATE_INT)) {
            return [$field->defaultValue(), [FormErrorKey::INTEGER]];
        }

        return [(int) $raw, []];
    }

    /**
     * @return array{0: mixed, 1: list<string>}
     */
    private function floatValue(FormFieldDefinition $field, mixed $raw): array
    {
        if (!is_scalar($raw) || !is_numeric((string) $raw)) {
            return [$field->defaultValue(), [FormErrorKey::NUMBER]];
        }

        return [(float) $raw, []];
    }

    /**
     * @return array{0: mixed, 1: list<string>}
     */
    private function jsonValue(FormFieldDefinition $field, mixed $raw): array
    {
        if (is_array($raw)) {
            return [array_values(array_filter($raw, static fn (mixed $value): bool => is_scalar($value))), []];
        }

        if (!is_string($raw)) {
            return [$field->defaultValue(), [FormErrorKey::INVALID]];
        }

        try {
            return [json_decode($raw, true, flags: JSON_THROW_ON_ERROR), []];
        } catch (JsonException) {
            return [$field->defaultValue(), [FormErrorKey::JSON]];
        }
    }
}
