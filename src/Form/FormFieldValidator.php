<?php

declare(strict_types=1);

namespace App\Form;

final readonly class FormFieldValidator
{
    /**
     * @return list<string>
     */
    public function validate(FormFieldDefinition $field, mixed $value): array
    {
        return [
            ...$this->optionErrors($field, $value),
            ...$this->validationErrors($field, $value),
        ];
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
                return [FormErrorKey::CHOICE];
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
                $errors[] = FormErrorKey::MIN_LENGTH;
            }

            if (isset($validation['max_length']) && is_numeric($validation['max_length']) && $length > (int) $validation['max_length']) {
                $errors[] = FormErrorKey::MAX_LENGTH;
            }

            if (isset($validation['pattern']) && is_string($validation['pattern']) && !$this->matchesPattern($value, $validation['pattern'])) {
                $errors[] = FormErrorKey::PATTERN;
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($validation['min']) && is_numeric($validation['min']) && $value < (float) $validation['min']) {
                $errors[] = FormErrorKey::MIN;
            }

            if (isset($validation['max']) && is_numeric($validation['max']) && $value > (float) $validation['max']) {
                $errors[] = FormErrorKey::MAX;
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
}
