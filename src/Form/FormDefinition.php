<?php

declare(strict_types=1);

namespace App\Form;

final readonly class FormDefinition
{
    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $id,
        private string $title,
        private string $method,
        private string $action,
        private array $fields,
        private array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'method' => $this->method,
            'action' => $this->action,
            'fields' => $this->fields,
            'metadata' => $this->metadata,
        ];
    }
}
