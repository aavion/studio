<?php

declare(strict_types=1);

namespace App\Content\Api;

use App\Entity\ContentSchema;
use App\Entity\ContentSchemaVersion;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SchemaApiReadModel
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function schemas(): array
    {
        $schemas = $this->entityManager->getRepository(ContentSchema::class)->findBy([], ['identifier' => 'ASC']);

        return array_values(array_filter(array_map($this->resource(...), $schemas)));
    }

    private function resource(ContentSchema $schema): ?array
    {
        $version = $schema->activeVersion();
        if (!$version instanceof ContentSchemaVersion) {
            return null;
        }

        return [
            'type' => 'content_schema',
            'id' => $schema->identifier(),
            'attributes' => [
                'identifier' => $schema->identifier(),
                'source' => $schema->source()->value,
                'locked' => $schema->locked(),
                'active_version' => $version->version(),
                'definition_hash' => $version->definitionHash(),
                'custom_twig' => $version->customTwig(),
                'fields' => $this->fields($version),
                'access' => [
                    'use_min_level' => $version->useMinLevel(),
                    'edit_min_level' => $version->editMinLevel(),
                    'manage_min_level' => $version->manageMinLevel(),
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(ContentSchemaVersion $version): array
    {
        $fields = $version->definition()['fields'] ?? [];
        if (!is_array($fields)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $field): ?array => is_array($field) && is_string($field['identifier'] ?? null)
                ? [
                    'identifier' => $field['identifier'],
                    'type' => is_string($field['type'] ?? null) ? $field['type'] : 'unknown',
                    'required' => (bool) ($field['required'] ?? false),
                ]
                : null,
            $fields,
        )));
    }
}
