<?php

declare(strict_types=1);

namespace App\Tests\Support\DatabaseSeed;

use App\Setup\SetupDefaultSeed;

final class TestDatabaseContentSeeder
{
    public static function seed(TestDatabaseSeedWriter $writer): void
    {
        foreach (self::contentItems() as $item) {
            self::seedContentItem($writer, $item);
        }
    }

    /**
     * @return list<array{
     *     content_uid: string,
     *     revision_uid: string,
     *     schema_uid: string,
     *     schema_version_uid: string,
     *     slug: string,
     *     custom_url: ?string,
     *     sort_order: int,
     *     metadata: array<string, mixed>,
     *     fields: array<string, array<string, mixed>>
     * }>
     */
    private static function contentItems(): array
    {
        $setupSeed = new SetupDefaultSeed();
        $homeContent = $setupSeed->homeContentItem(['en', 'de']);
        $homeRevision = $setupSeed->homeContentRevision();
        $setupSchema = $setupSeed->contentSchema();
        $setupSchemaVersion = $setupSeed->contentSchemaVersion();

        return [
            [
                'content_uid' => $homeContent['uid'],
                'revision_uid' => $homeRevision['uid'],
                'schema_uid' => $setupSchema['uid'],
                'schema_version_uid' => $setupSchemaVersion['uid'],
                'slug' => $homeContent['slug'],
                'custom_url' => null,
                'sort_order' => $homeContent['sort_order'],
                'metadata' => ['template_hint' => $homeContent['template_hint']],
                'fields' => [
                    'title' => ['en' => 'Welcome to Studio', 'de' => 'Willkommen in Studio'],
                    'subtitle' => ['en' => 'A flexible content seed for tests.', 'de' => 'Ein flexibler Content-Seed fuer Tests.'],
                    'body' => [
                        'en' => ['html' => '<p>This page is seeded by the PHPUnit bootstrap.</p>'],
                        'de' => ['html' => '<p>Diese Seite wird vom PHPUnit-Bootstrap erzeugt.</p>'],
                    ],
                    'seo_title' => ['en' => 'Studio test home', 'de' => 'Studio Test-Startseite'],
                ],
            ],
            [
                'content_uid' => '20000000-0000-7000-8000-000000000002',
                'revision_uid' => '20000000-0000-7000-8000-000000000102',
                'schema_uid' => $setupSchema['uid'],
                'schema_version_uid' => $setupSchemaVersion['uid'],
                'slug' => 'about',
                'custom_url' => '/about',
                'sort_order' => 20,
                'metadata' => ['template_hint' => 'standard'],
                'fields' => [
                    'title' => ['en' => 'About Studio', 'de' => 'Ueber Studio'],
                    'subtitle' => ['en' => 'A small page for resolver and menu tests.', 'de' => 'Eine kleine Seite fuer Resolver- und Menue-Tests.'],
                    'body' => [
                        'en' => ['html' => '<p>Studio models content through schemas, revisions, and fields.</p>'],
                        'de' => ['html' => '<p>Studio modelliert Inhalte ueber Schemata, Revisionen und Felder.</p>'],
                    ],
                    'seo_title' => ['en' => 'About Studio', 'de' => 'Ueber Studio'],
                ],
            ],
            [
                'content_uid' => '20000000-0000-7000-8000-000000000003',
                'revision_uid' => '20000000-0000-7000-8000-000000000103',
                'schema_uid' => '10000000-0000-7000-8000-000000000002',
                'schema_version_uid' => '10000000-0000-7000-8000-000000000102',
                'slug' => 'first-update',
                'custom_url' => '/news/first-update',
                'sort_order' => 30,
                'metadata' => ['template_hint' => 'article'],
                'fields' => [
                    'title' => ['en' => 'First seeded article', 'de' => 'Erster Seed-Artikel'],
                    'subtitle' => ['en' => 'Useful sample content for tests.', 'de' => 'Nuetzlicher Beispielinhalt fuer Tests.'],
                    'teaser' => ['en' => 'The test database includes a complete article.', 'de' => 'Die Testdatenbank enthaelt einen vollstaendigen Artikel.'],
                    'body' => [
                        'en' => ['html' => '<p>Articles can be queried by schema, status, language, and active revision.</p>'],
                        'de' => ['html' => '<p>Artikel koennen nach Schema, Status, Sprache und aktiver Revision abgefragt werden.</p>'],
                    ],
                    'tags' => ['en' => ['core', 'seed'], 'de' => ['core', 'seed']],
                ],
            ],
        ];
    }

    /**
     * @param array{
     *     content_uid: string,
     *     revision_uid: string,
     *     schema_uid: string,
     *     schema_version_uid: string,
     *     slug: string,
     *     custom_url: ?string,
     *     sort_order: int,
     *     metadata: array<string, mixed>,
     *     fields: array<string, array<string, mixed>>
     * } $item
     */
    private static function seedContentItem(TestDatabaseSeedWriter $writer, array $item): void
    {
        $writer->insert('content_item', [
            'uid' => $item['content_uid'],
            'slug' => $item['slug'],
            'status' => 'published',
            'parent_uid' => '/',
            'sort_order' => $item['sort_order'],
            'custom_url' => $item['custom_url'],
            'redirect_target' => null,
            'schema_uid' => $item['schema_uid'],
            'schema_version' => 1,
            'active_revision_uid' => null,
            'version' => 1,
            'available_languages' => $writer->json(['en', 'de']),
            'available_variants' => $writer->json(['default']),
            'visibility' => 'public',
            'acl_restrictions' => $writer->json([]),
            'view_min_level' => 0,
            'view_group_identifiers' => null,
            'edit_min_level' => 3,
            'edit_group_identifiers' => null,
            'manage_min_level' => 6,
            'manage_group_identifiers' => null,
            'metadata' => $writer->json($item['metadata']),
        ]);

        $writer->insert('content_revision', [
            'uid' => $item['revision_uid'],
            'content_uid' => $item['content_uid'],
            'version' => 1,
            'schema_uid' => $item['schema_uid'],
            'schema_version_uid' => $item['schema_version_uid'],
            'change_summary' => 'Seeded initial revision.',
            'metadata' => $writer->json(['seed' => true]),
        ]);

        $fieldIndex = 1;

        foreach ($item['fields'] as $fieldIdentifier => $localizedValues) {
            foreach ($localizedValues as $language => $fieldContent) {
                $writer->insert('content_field_value', [
                    'uid' => $writer->fieldValueUid($item['content_uid'], $fieldIndex),
                    'revision_uid' => $item['revision_uid'],
                    'language' => $language,
                    'variant' => 'default',
                    'field_identifier' => $fieldIdentifier,
                    'field_content' => $writer->json($fieldContent),
                ]);
                ++$fieldIndex;
            }
        }

        $writer->update('content_item', ['active_revision_uid' => $item['revision_uid']], ['uid' => $item['content_uid']]);
        $suffix = substr($item['content_uid'], -1);
        $writer->seedStateMarker(sprintf('00000000-0000-7000-8000-00000000094%s', $suffix), 'content_item', $item['content_uid'], 'created', 'system', null, ['slug' => $item['slug']]);
        $writer->seedStateMarker(sprintf('00000000-0000-7000-8000-00000000095%s', $suffix), 'content_item', $item['content_uid'], 'published', 'system', 'published', ['revision_uid' => $item['revision_uid']]);
        $writer->seedStateMarker(sprintf('00000000-0000-7000-8000-00000000096%s', $suffix), 'content_revision', $item['revision_uid'], 'created', 'system', null, ['content_uid' => $item['content_uid']]);
    }

    private function __construct()
    {
    }
}
