<?php

declare(strict_types=1);

namespace App\Form;

final readonly class FormBuilder
{
    /**
     * @param iterable<FormFieldDefinition> $fields
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     * @param list<string> $formErrors
     * @param array<string, mixed> $metadata
     */
    public function build(
        string $id,
        string $title,
        iterable $fields,
        array $values = [],
        array $errors = [],
        array $formErrors = [],
        string $method = 'post',
        string $action = '',
        array $metadata = [],
    ): FormDefinition {
        $fieldList = [];

        foreach ($fields as $field) {
            $fieldList[] = $this->fieldArray(
                $field,
                $values[$field->name()] ?? $field->defaultValue(),
                $errors[$field->name()] ?? [],
            );
        }

        usort(
            $fieldList,
            static fn (array $left, array $right): int => [
                $left['sort_order'],
                $left['label'],
                $left['name'],
            ] <=> [
                $right['sort_order'],
                $right['label'],
                $right['name'],
            ],
        );

        return new FormDefinition($id, $title, $method, $action, $fieldList, $formErrors, $metadata);
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    private function fieldArray(FormFieldDefinition $field, mixed $value, array $errors): array
    {
        $validation = $field->validation();

        return [
            'id' => $this->fieldId($field->name()),
            'name' => $field->name(),
            'label' => $field->label(),
            'help' => $field->help(),
            'value' => $value,
            'default' => $field->defaultValue(),
            'value_type' => $field->valueType()->value,
            'input_type' => $field->inputType()->value,
            'options' => $field->options(),
            'required' => true === ($validation['required'] ?? false),
            'attributes' => $this->validationAttributes($validation),
            'validation' => $validation,
            'metadata' => $field->metadata(),
            'sort_order' => $field->sortOrder(),
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $validation
     *
     * @return array<string, scalar>
     */
    private function validationAttributes(array $validation): array
    {
        $attributes = [];

        foreach ([
            'pattern' => 'pattern',
            'min' => 'min',
            'max' => 'max',
            'min_length' => 'minlength',
            'max_length' => 'maxlength',
            'step' => 'step',
        ] as $rule => $attribute) {
            $value = $validation[$rule] ?? null;

            if (is_scalar($value)) {
                $attributes[$attribute] = $value;
            }
        }

        return $attributes;
    }

    private function fieldId(string $name): string
    {
        return 'field_'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($name));
    }
}
