<?php

declare(strict_types=1);

namespace App\Tests\Support\DatabaseSeed;

use App\Setup\SetupDefaultSeed;

final class TestDatabaseSchemaSeeder
{
    public static function seed(TestDatabaseSeedWriter $writer): void
    {
        foreach (self::schemas() as $index => $schema) {
            $writer->insert('content_schema', [
                'uid' => $schema['schema_uid'],
                'identifier' => $schema['identifier'],
                'source' => 'preset',
                'locked' => 1,
                'active_version_uid' => null,
                'labels' => $writer->json($schema['labels']),
                'descriptions' => $writer->json($schema['description']),
                'metadata' => $writer->json(['seed' => true]),
            ]);
            $writer->seedStateMarker(sprintf('00000000-0000-7000-8000-00000000092%d', $index * 3), 'content_schema', $schema['schema_uid'], 'created', 'system', null, ['identifier' => $schema['identifier']]);
            $writer->seedStateMarker(sprintf('00000000-0000-7000-8000-00000000092%d', $index * 3 + 1), 'content_schema', $schema['schema_uid'], 'modified', 'system', null, ['identifier' => $schema['identifier']]);

            $writer->insert('content_schema_version', [
                'uid' => $schema['version_uid'],
                'schema_uid' => $schema['schema_uid'],
                'version' => 1,
                'title' => $writer->json($schema['title']),
                'description' => $writer->json($schema['description']),
                'definition' => $writer->json($schema['definition']),
                'custom_twig' => null,
                'definition_hash' => self::definitionHash($schema['title'], $schema['description'], $schema['definition'], null),
                'use_min_level' => 0,
                'use_group_identifiers' => null,
                'edit_min_level' => 3,
                'edit_group_identifiers' => null,
                'manage_min_level' => 6,
                'manage_group_identifiers' => null,
                'metadata' => $writer->json(['seed' => true]),
            ]);
            $writer->seedStateMarker(sprintf('00000000-0000-7000-8000-00000000092%d', $index * 3 + 2), 'content_schema_version', $schema['version_uid'], 'activated', 'system', '1', ['schema_uid' => $schema['schema_uid']]);

            $writer->update('content_schema', ['active_version_uid' => $schema['version_uid']], ['uid' => $schema['schema_uid']]);
        }
    }

    /**
     * @return list<array{
     *     schema_uid: string,
     *     version_uid: string,
     *     identifier: string,
     *     labels: array<string, string>,
     *     title: array<string, string>,
     *     description: array<string, string>,
     *     definition: array<string, mixed>
     * }>
     */
    private static function schemas(): array
    {
        $setupSeed = new SetupDefaultSeed();
        $setupSchema = $setupSeed->contentSchema();
        $setupVersion = $setupSeed->contentSchemaVersion();

        return [
            [
                'schema_uid' => $setupSchema['uid'],
                'version_uid' => $setupVersion['uid'],
                'identifier' => $setupSchema['identifier'],
                'labels' => $setupSchema['labels'],
                'title' => $setupVersion['title'],
                'description' => $setupSchema['descriptions'],
                'definition' => $setupVersion['definition'],
            ],
            [
                'schema_uid' => '10000000-0000-7000-8000-000000000002',
                'version_uid' => '10000000-0000-7000-8000-000000000102',
                'identifier' => 'article',
                'labels' => ['en' => 'Article', 'de' => 'Artikel'],
                'title' => ['en' => 'Article schema', 'de' => 'Artikelschema'],
                'description' => ['en' => 'Editorial articles with teaser and tags.', 'de' => 'Redaktionelle Artikel mit Teaser und Tags.'],
                'definition' => self::articleDefinition(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function articleDefinition(): array
    {
        return [
            'fields' => [
                ['identifier' => 'title', 'type' => 'text', 'required' => true, 'localized' => true],
                ['identifier' => 'subtitle', 'type' => 'text', 'required' => true, 'localized' => true],
                ['identifier' => 'teaser', 'type' => 'text', 'required' => true, 'localized' => true],
                ['identifier' => 'body', 'type' => 'rich_text', 'required' => true, 'localized' => true],
                ['identifier' => 'tags', 'type' => 'string_list', 'required' => false, 'localized' => false],
            ],
            'order' => ['title', 'subtitle', 'teaser', 'body', 'tags'],
        ];
    }

    /**
     * @param array<string, string> $title
     * @param array<string, string> $description
     * @param array<string, mixed> $definition
     */
    private static function definitionHash(array $title, array $description, array $definition, ?string $customTwig): string
    {
        return hash('sha256', json_encode([
            'title' => $title,
            'description' => $description,
            'definition' => $definition,
            'custom_twig' => $customTwig,
        ], JSON_THROW_ON_ERROR));
    }

    private function __construct()
    {
    }
}
