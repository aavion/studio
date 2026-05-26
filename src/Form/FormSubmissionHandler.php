<?php

declare(strict_types=1);

namespace App\Form;

use App\Core\Config\ConfigValueType;

final readonly class FormSubmissionHandler
{
    /**
     * @param iterable<FormFieldDefinition> $fields
     * @param array<string, mixed> $submitted
     */
    public function submit(iterable $fields, array $submitted): FormSubmissionResult
    {
        $values = [];
        $errors = [];

        foreach ($fields as $field) {
            [$value, $fieldErrors] = $this->fieldValue($field, $submitted);
            $values[$field->name()] = $value;

            if ([] !== $fieldErrors) {
                $errors[$field->name()] = $fieldErrors;
            }
        }

        return new FormSubmissionResult($values, $errors);
    }

    /**
     * @param array<string, mixed> $submitted
     *
     * @return array{0: mixed, 1: list<string>}
     */
    private function fieldValue(FormFieldDefinition $field, array $submitted): array
    {
        $raw = $submitted[$field->name()] ?? null;
        $validation = $field->validation();

        if (ConfigValueType::Boolean === $field->valueType()) {
            return [$this->booleanValue($raw), []];
        }

        if ($this->isEmpty($raw)) {
            if (true === ($validation['required'] ?? false)) {
                return [$field->defaultValue(), ['admin.settings.form.errors.required']];
            }

            return [null, []];
        }

        [$value, $errors] = $this->castValue($field, $raw);

        if ([] !== $errors) {
            return [$field->defaultValue(), $errors];
        }

        $errors = [
            ...$this->optionErrors($field, $value),
            ...$this->validationErrors($field, $value),
        ];

        return [$value, $errors];
    }

    /**
     * @return array{0: mixed, 1: list<string>}
     */
    private function castValue(FormFieldDefinition $field, mixed $raw): array
    {
        return match ($field->valueType()) {
            ConfigValueType::String => is_scalar($raw)
                ? [trim((string) $raw), []]
                : [$field->defaultValue(), ['admin.settings.form.errors.invalid']],
            ConfigValueType::Integer => $this->integerValue($field, $raw),
            ConfigValueType::Float => $this->floatValue($field, $raw),
            ConfigValueType::Json => $this->jsonValue($field, $raw),
            ConfigValueType::Boolean => [$this->booleanValue($raw), []],
        };
    }

    /**
     * @return array{0: mixed, 1: list<string>}
     */
    private function integerValue(FormFieldDefinition $field, mixed $raw): array
    {
        if (!is_scalar($raw) || false === filter_var((string) $raw, FILTER_VALIDATE_INT)) {
            return [$field->defaultValue(), ['admin.settings.form.errors.integer']];
        }

        return [(int) $raw, []];
    }

    /**
     * @return array{0: mixed, 1: list<string>}
     */
    private function floatValue(FormFieldDefinition $field, mixed $raw): array
    {
        if (!is_scalar($raw) || !is_numeric((string) $raw)) {
            return [$field->defaultValue(), ['admin.settings.form.errors.number']];
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
            return [$field->defaultValue(), ['admin.settings.form.errors.invalid']];
        }

        try {
            return [json_decode($raw, true, flags: JSON_THROW_ON_ERROR), []];
        } catch (\JsonException) {
            return [$field->defaultValue(), ['admin.settings.form.errors.json']];
        }
    }

    private function booleanValue(mixed $raw): bool
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
     * @return list<string>
     */
    private function optionErrors(FormFieldDefinition $field, mixed $value): array
    {
        $options = $field->options();

        if ([] === $options) {
            return [];
        }

        $allowed = array_map('strval', array_keys($options));
        $values = is_array($value) ? $value : [$value];

        foreach ($values as $singleValue) {
            if (!is_scalar($singleValue) || !in_array((string) $singleValue, $allowed, true)) {
                return ['admin.settings.form.errors.choice'];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function validationErrors(FormFieldDefinition $field, mixed $value): array
    {
        $validation = $field->validation();
        $errors = [];

        if (is_string($value)) {
            $length = mb_strlen($value);

            if (isset($validation['min_length']) && is_numeric($validation['min_length']) && $length < (int) $validation['min_length']) {
                $errors[] = 'admin.settings.form.errors.min_length';
            }

            if (isset($validation['max_length']) && is_numeric($validation['max_length']) && $length > (int) $validation['max_length']) {
                $errors[] = 'admin.settings.form.errors.max_length';
            }

            if (isset($validation['pattern']) && is_string($validation['pattern']) && !$this->matchesPattern($value, $validation['pattern'])) {
                $errors[] = 'admin.settings.form.errors.pattern';
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($validation['min']) && is_numeric($validation['min']) && $value < (float) $validation['min']) {
                $errors[] = 'admin.settings.form.errors.min';
            }

            if (isset($validation['max']) && is_numeric($validation['max']) && $value > (float) $validation['max']) {
                $errors[] = 'admin.settings.form.errors.max';
            }
        }

        return $errors;
    }

    private function matchesPattern(string $value, string $pattern): bool
    {
        $delimiter = '~';
        $regex = $delimiter.str_replace($delimiter, '\\'.$delimiter, $pattern).$delimiter;

        return 1 === @preg_match($regex, $value);
    }

    private function isEmpty(mixed $raw): bool
    {
        return null === $raw || [] === $raw || (is_string($raw) && '' === trim($raw));
    }
}
