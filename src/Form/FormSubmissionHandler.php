<?php

declare(strict_types=1);

namespace App\Form;

use App\Core\Config\ConfigValueType;

final readonly class FormSubmissionHandler
{
    private FormValueCaster $caster;
    private FormFieldValidator $validator;

    public function __construct(
        ?FormValueCaster $caster = null,
        ?FormFieldValidator $validator = null,
    ) {
        $this->caster = $caster ?? new FormValueCaster();
        $this->validator = $validator ?? new FormFieldValidator();
    }

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
            return [$this->caster->booleanValue($raw), []];
        }

        if (ConfigValueType::Json === $field->valueType() && FormInputType::MultiSelect === $field->inputType() && $this->isEmpty($raw)) {
            return [[], []];
        }

        if ($this->isEmpty($raw)) {
            if (true === ($validation['required'] ?? false)) {
                return [$field->defaultValue(), [FormErrorKey::REQUIRED]];
            }

            return [null, []];
        }

        [$value, $errors] = $this->caster->cast($field, $raw);

        if ([] !== $errors) {
            return [$field->defaultValue(), $errors];
        }

        return [$value, $this->validator->validate($field, $value)];
    }

    private function isEmpty(mixed $raw): bool
    {
        return null === $raw || [] === $raw || (is_string($raw) && '' === trim($raw));
    }
}
