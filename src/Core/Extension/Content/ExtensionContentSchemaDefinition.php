<?php

declare(strict_types=1);

namespace App\Core\Extension\Content;

use App\Core\Message\MessageException;
use App\Core\Extension\ExtensionMessageKey;

final readonly class ExtensionContentSchemaDefinition
{
    /**
     * @param array<string, string> $labels
     * @param array<string, string> $descriptions
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $name,
        private array $labels,
        private array $definition,
        private array $descriptions = [],
        private ?string $customTwig = null,
        private array $metadata = [],
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_CONTENT_SCHEMA_CONTRIBUTION_INVALID, [
                '%reason%' => 'schema_identifier_invalid',
            ], ['schema' => $name]);
        }
    }

    public static function create(string $name, array $labels, array $definition, array $descriptions = [], ?string $customTwig = null, array $metadata = []): self
    {
        return new self($name, $labels, $definition, $descriptions, $customTwig, $metadata);
    }

    public function identifier(string $extensionName): string
    {
        return ExtensionContentSchemaIdentifier::create($extensionName, $this->name);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return $this->labels;
    }

    /**
     * @return array<string, string>
     */
    public function descriptions(): array
    {
        return $this->descriptions;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->definition;
    }

    public function customTwig(): ?string
    {
        return $this->customTwig;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
