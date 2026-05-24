<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Core\Access\AccessLevel;
use PDO;
use RuntimeException;

final class TestDatabaseSeeder
{
    private const NOW = '2026-05-23 21:00:00';

    public static function seed(string $databasePath): void
    {
        $pdo = new PDO('sqlite:'.$databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::seedConfig($pdo);
        self::seedAcl($pdo);
        self::seedExtensions($pdo);
        self::seedSchemas($pdo);
        self::seedContent($pdo);
        self::seedMenus($pdo);
    }

    private static function seedConfig(PDO $pdo): void
    {
        self::insert($pdo, 'config_entry', [
            'config_key' => 'user.default_acl_group',
            'value' => self::json('registered'),
            'value_type' => 'string',
            'sensitive' => 0,
            'modified_at' => self::NOW,
            'modified_by' => 'system',
        ]);
        self::insert($pdo, 'config_entry', [
            'config_key' => 'content.default_locale',
            'value' => self::json('en'),
            'value_type' => 'string',
            'sensitive' => 0,
            'modified_at' => self::NOW,
            'modified_by' => 'system',
        ]);
        self::insert($pdo, 'config_entry', [
            'config_key' => 'content.enabled_locales',
            'value' => self::json(['en', 'de']),
            'value_type' => 'json',
            'sensitive' => 0,
            'modified_at' => self::NOW,
            'modified_by' => 'system',
        ]);
        self::insert($pdo, 'config_entry', [
            'config_key' => 'content.default_variant',
            'value' => self::json('default'),
            'value_type' => 'string',
            'sensitive' => 0,
            'modified_at' => self::NOW,
            'modified_by' => 'system',
        ]);
        self::insert($pdo, 'config_entry', [
            'config_key' => 'content.revision_retention_count',
            'value' => self::json(10),
            'value_type' => 'integer',
            'sensitive' => 0,
            'modified_at' => self::NOW,
            'modified_by' => 'system',
        ]);
    }

    private static function seedAcl(PDO $pdo): void
    {
        $groups = [
            ['00000000-0000-0000-0000-000000000101', 'registered', ['en' => 'Registered', 'de' => 'Registriert'], AccessLevel::REGISTERED, true, true],
            ['00000000-0000-0000-0000-000000000103', 'editor', ['en' => 'Editor', 'de' => 'Redaktion'], AccessLevel::EDITOR, false, true],
            ['00000000-0000-0000-0000-000000000106', 'manager', ['en' => 'Manager', 'de' => 'Management'], AccessLevel::MANAGER, false, true],
            ['00000000-0000-0000-0000-000000000109', 'admin', ['en' => 'Admin', 'de' => 'Administration'], AccessLevel::ADMIN, true, false],
        ];

        foreach ($groups as $index => [$uid, $identifier, $name, $accessLevel, $locked, $allowEmpty]) {
            self::insert($pdo, 'acl_group', [
                'uid' => $uid,
                'identifier' => $identifier,
                'name' => self::json($name),
                'access_level' => $accessLevel,
                'locked' => $locked ? 1 : 0,
                'allow_empty' => $allowEmpty ? 1 : 0,
                'metadata' => self::json(['preset' => true]),
            ]);
            self::seedStateMarker($pdo, sprintf('00000000-0000-0000-0000-00000000091%d', $index), 'acl_group', $uid, 'created', 'test_seed', null, ['identifier' => $identifier]);
        }

        self::insert($pdo, 'user_account', [
            'uid' => '00000000-0000-0000-0000-000000000201',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password_hash' => self::adminPasswordHash(),
            'profile' => self::json([
                'display_name' => 'Test Administrator',
                'locale' => 'en',
                'seed_password' => 'APP_SECRET',
            ]),
            'settings' => self::json(['language' => 'default']),
            'status' => 'active',
        ]);
        self::seedStateMarker($pdo, '00000000-0000-0000-0000-000000000901', 'user_account', '00000000-0000-0000-0000-000000000201', 'created', 'test_seed');
        self::seedStateMarker($pdo, '00000000-0000-0000-0000-000000000902', 'user_account', '00000000-0000-0000-0000-000000000201', 'password_changed', 'test_seed');
        self::seedStateMarker($pdo, '00000000-0000-0000-0000-000000000903', 'user_account', '00000000-0000-0000-0000-000000000201', 'status_changed', 'test_seed', 'active');

        self::insert($pdo, 'user_acl_group', [
            'user_uid' => '00000000-0000-0000-0000-000000000201',
            'group_uid' => '00000000-0000-0000-0000-000000000109',
        ]);

        foreach ([
            ['00000000-0000-0000-0000-000000000301', 'seedrw', 'test_seed_read_write_key', 'read_write', null],
            ['00000000-0000-0000-0000-000000000302', 'seedro', 'test_seed_read_only_key', 'read_only', null],
            ['00000000-0000-0000-0000-000000000303', 'seedrv', 'test_seed_revoked_key', 'revoked', self::NOW],
        ] as [$uid, $prefix, $plainKey, $status, $revokedAt]) {
            self::insert($pdo, 'api_key', [
                'uid' => $uid,
                'prefix' => $prefix,
                'hmac_hash' => self::apiKeyHmacHash($plainKey),
                'encrypted_key' => self::encryptApiKey($plainKey),
                'user_uid' => '00000000-0000-0000-0000-000000000201',
                'status' => $status,
                'created_at' => self::NOW,
                'revoked_at' => $revokedAt,
            ]);
        }
    }

    private static function seedStateMarker(
        PDO $pdo,
        string $uid,
        string $subjectType,
        string $subjectUid,
        string $markerKey,
        ?string $markerBy = null,
        ?string $markerValue = null,
        array $metadata = [],
    ): void {
        self::insert($pdo, 'state_marker', [
            'uid' => $uid,
            'subject_type' => $subjectType,
            'subject_uid' => $subjectUid,
            'marker_key' => $markerKey,
            'marker_at' => self::NOW,
            'marker_by' => $markerBy,
            'marker_value' => $markerValue,
            'metadata' => self::json($metadata),
        ]);
    }

    private static function seedExtensions(PDO $pdo): void
    {
        self::insert($pdo, 'extension_package', [
            'uid' => '00000000-0000-0000-0000-000000000401',
            'package_type' => 'theme',
            'package_name' => 'studio_default',
            'path' => 'themes/studio-default',
            'manifest_version' => '1',
            'installed_version' => '0.1.0-dev',
            'status' => 'active',
            'metadata' => self::json(['preset' => true]),
            'modified_at' => self::NOW,
        ]);
    }

    private static function seedSchemas(PDO $pdo): void
    {
        $schemas = [
            [
                'schema_uid' => '10000000-0000-0000-0000-000000000001',
                'version_uid' => '10000000-0000-0000-0000-000000000101',
                'identifier' => 'static_page',
                'labels' => ['en' => 'Static page', 'de' => 'Statische Seite'],
                'title' => ['en' => 'Static page schema', 'de' => 'Schema fuer statische Seiten'],
                'description' => ['en' => 'General pages with a rich text body.', 'de' => 'Allgemeine Seiten mit Rich-Text-Inhalt.'],
                'definition' => self::staticPageDefinition(),
            ],
            [
                'schema_uid' => '10000000-0000-0000-0000-000000000002',
                'version_uid' => '10000000-0000-0000-0000-000000000102',
                'identifier' => 'article',
                'labels' => ['en' => 'Article', 'de' => 'Artikel'],
                'title' => ['en' => 'Article schema', 'de' => 'Artikelschema'],
                'description' => ['en' => 'Editorial articles with teaser and tags.', 'de' => 'Redaktionelle Artikel mit Teaser und Tags.'],
                'definition' => self::articleDefinition(),
            ],
        ];

        foreach ($schemas as $index => $schema) {
            self::insert($pdo, 'content_schema', [
                'uid' => $schema['schema_uid'],
                'identifier' => $schema['identifier'],
                'source' => 'preset',
                'locked' => 1,
                'active_version_uid' => null,
                'labels' => self::json($schema['labels']),
                'descriptions' => self::json($schema['description']),
                'metadata' => self::json(['seed' => true]),
            ]);
            self::seedStateMarker($pdo, sprintf('00000000-0000-0000-0000-00000000092%d', $index * 3), 'content_schema', $schema['schema_uid'], 'created', 'system', null, ['identifier' => $schema['identifier']]);
            self::seedStateMarker($pdo, sprintf('00000000-0000-0000-0000-00000000092%d', $index * 3 + 1), 'content_schema', $schema['schema_uid'], 'modified', 'system', null, ['identifier' => $schema['identifier']]);

            self::insert($pdo, 'content_schema_version', [
                'uid' => $schema['version_uid'],
                'schema_uid' => $schema['schema_uid'],
                'version' => 1,
                'title' => self::json($schema['title']),
                'description' => self::json($schema['description']),
                'definition' => self::json($schema['definition']),
                'custom_twig' => null,
                'definition_hash' => hash('sha256', self::json($schema['definition'])),
                'use_min_level' => 0,
                'use_group_identifiers' => null,
                'edit_min_level' => 3,
                'edit_group_identifiers' => null,
                'manage_min_level' => 6,
                'manage_group_identifiers' => null,
                'metadata' => self::json(['seed' => true]),
            ]);
            self::seedStateMarker($pdo, sprintf('00000000-0000-0000-0000-00000000092%d', $index * 3 + 2), 'content_schema_version', $schema['version_uid'], 'activated', 'system', '1', ['schema_uid' => $schema['schema_uid']]);

            self::update($pdo, 'content_schema', ['active_version_uid' => $schema['version_uid']], ['uid' => $schema['schema_uid']]);
        }
    }

    private static function seedContent(PDO $pdo): void
    {
        self::seedContentItem($pdo, [
            'content_uid' => '20000000-0000-0000-0000-000000000001',
            'revision_uid' => '20000000-0000-0000-0000-000000000101',
            'schema_uid' => '10000000-0000-0000-0000-000000000001',
            'schema_version_uid' => '10000000-0000-0000-0000-000000000101',
            'slug' => 'home',
            'custom_url' => '/',
            'sort_order' => 10,
            'metadata' => ['template_hint' => 'home'],
            'fields' => [
                'title' => ['en' => 'Welcome to Studio', 'de' => 'Willkommen in Studio'],
                'subtitle' => ['en' => 'A flexible content seed for tests.', 'de' => 'Ein flexibler Content-Seed fuer Tests.'],
                'body' => [
                    'en' => ['html' => '<p>This page is seeded by the PHPUnit bootstrap.</p>'],
                    'de' => ['html' => '<p>Diese Seite wird vom PHPUnit-Bootstrap erzeugt.</p>'],
                ],
                'seo_title' => ['en' => 'Studio test home', 'de' => 'Studio Test-Startseite'],
            ],
        ]);

        self::seedContentItem($pdo, [
            'content_uid' => '20000000-0000-0000-0000-000000000002',
            'revision_uid' => '20000000-0000-0000-0000-000000000102',
            'schema_uid' => '10000000-0000-0000-0000-000000000001',
            'schema_version_uid' => '10000000-0000-0000-0000-000000000101',
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
        ]);

        self::seedContentItem($pdo, [
            'content_uid' => '20000000-0000-0000-0000-000000000003',
            'revision_uid' => '20000000-0000-0000-0000-000000000103',
            'schema_uid' => '10000000-0000-0000-0000-000000000002',
            'schema_version_uid' => '10000000-0000-0000-0000-000000000102',
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
        ]);
    }

    /**
     * @param array{
     *     content_uid: string,
     *     revision_uid: string,
     *     schema_uid: string,
     *     schema_version_uid: string,
     *     slug: string,
     *     custom_url: string,
     *     sort_order: int,
     *     metadata: array<string, mixed>,
     *     fields: array<string, array<string, mixed>>
     * } $item
     */
    private static function seedContentItem(PDO $pdo, array $item): void
    {
        self::insert($pdo, 'content_item', [
            'uid' => $item['content_uid'],
            'slug' => $item['slug'],
            'status' => 'published',
            'parent_uid' => null,
            'sort_order' => $item['sort_order'],
            'custom_url' => $item['custom_url'],
            'redirect_target' => null,
            'schema_uid' => $item['schema_uid'],
            'schema_version' => 1,
            'active_revision_uid' => null,
            'version' => 1,
            'available_languages' => self::json(['en', 'de']),
            'available_variants' => self::json(['default']),
            'visibility' => 'public',
            'acl_restrictions' => self::json([]),
            'view_min_level' => 0,
            'view_group_identifiers' => null,
            'edit_min_level' => 3,
            'edit_group_identifiers' => null,
            'manage_min_level' => 6,
            'manage_group_identifiers' => null,
            'metadata' => self::json($item['metadata']),
        ]);

        self::insert($pdo, 'content_revision', [
            'uid' => $item['revision_uid'],
            'content_uid' => $item['content_uid'],
            'version' => 1,
            'schema_uid' => $item['schema_uid'],
            'schema_version_uid' => $item['schema_version_uid'],
            'change_summary' => 'Seeded initial revision.',
            'metadata' => self::json(['seed' => true]),
        ]);

        $fieldIndex = 1;

        foreach ($item['fields'] as $fieldIdentifier => $localizedValues) {
            foreach ($localizedValues as $language => $fieldContent) {
                self::insert($pdo, 'content_field_value', [
                    'uid' => self::fieldValueUid($item['content_uid'], $fieldIndex),
                    'revision_uid' => $item['revision_uid'],
                    'language' => $language,
                    'variant' => 'default',
                    'field_identifier' => $fieldIdentifier,
                    'field_content' => self::json($fieldContent),
                ]);
                ++$fieldIndex;
            }
        }

        self::update($pdo, 'content_item', ['active_revision_uid' => $item['revision_uid']], ['uid' => $item['content_uid']]);
        $suffix = substr($item['content_uid'], -1);
        self::seedStateMarker($pdo, sprintf('00000000-0000-0000-0000-00000000094%s', $suffix), 'content_item', $item['content_uid'], 'created', 'system', null, ['slug' => $item['slug']]);
        self::seedStateMarker($pdo, sprintf('00000000-0000-0000-0000-00000000095%s', $suffix), 'content_item', $item['content_uid'], 'published', 'system', 'published', ['revision_uid' => $item['revision_uid']]);
        self::seedStateMarker($pdo, sprintf('00000000-0000-0000-0000-00000000096%s', $suffix), 'content_revision', $item['revision_uid'], 'created', 'system', null, ['content_uid' => $item['content_uid']]);
    }

    private static function seedMenus(PDO $pdo): void
    {
        self::insert($pdo, 'site_menu', [
            'uid' => '30000000-0000-0000-0000-000000000001',
            'identifier' => 'main',
            'labels' => self::json(['en' => 'Main navigation', 'de' => 'Hauptnavigation']),
            'active' => 1,
            'metadata' => self::json(['seed' => true]),
        ]);

        foreach ([
            ['30000000-0000-0000-0000-000000000101', 10, ['en' => 'Home', 'de' => 'Start'], '/'],
            ['30000000-0000-0000-0000-000000000102', 20, ['en' => 'About', 'de' => 'Ueber'], '/about'],
            ['30000000-0000-0000-0000-000000000103', 30, ['en' => 'News', 'de' => 'News'], '/news/first-update'],
        ] as [$uid, $sortOrder, $labels, $targetValue]) {
            self::insert($pdo, 'site_menu_item', [
                'uid' => $uid,
                'menu_uid' => '30000000-0000-0000-0000-000000000001',
                'parent_uid' => null,
                'sort_order' => $sortOrder,
                'labels' => self::json($labels),
                'target_type' => 'url',
                'target_value' => $targetValue,
                'view_min_level' => 0,
                'view_group_identifiers' => null,
                'metadata' => self::json(['seed' => true]),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function staticPageDefinition(): array
    {
        return [
            'fields' => [
                ['identifier' => 'title', 'type' => 'text', 'required' => true, 'localized' => true],
                ['identifier' => 'subtitle', 'type' => 'text', 'required' => true, 'localized' => true],
                ['identifier' => 'body', 'type' => 'rich_text', 'required' => true, 'localized' => true],
                ['identifier' => 'seo_title', 'type' => 'text', 'required' => false, 'localized' => true],
            ],
            'order' => ['title', 'subtitle', 'body', 'seo_title'],
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
     * @param array<string, mixed> $values
     */
    private static function insert(PDO $pdo, string $table, array $values): void
    {
        $columns = array_keys($values);
        $placeholders = array_map(static fn (string $column): string => ':'.$column, $columns);

        $statement = $pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
        ));

        foreach ($values as $column => $value) {
            $statement->bindValue(':'.$column, $value);
        }

        $statement->execute();
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $criteria
     */
    private static function update(PDO $pdo, string $table, array $values, array $criteria): void
    {
        $set = array_map(static fn (string $column): string => $column.' = :set_'.$column, array_keys($values));
        $where = array_map(static fn (string $column): string => $column.' = :where_'.$column, array_keys($criteria));

        $statement = $pdo->prepare(sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', $set),
            implode(' AND ', $where),
        ));

        foreach ($values as $column => $value) {
            $statement->bindValue(':set_'.$column, $value);
        }

        foreach ($criteria as $column => $value) {
            $statement->bindValue(':where_'.$column, $value);
        }

        $statement->execute();
    }

    private static function fieldValueUid(string $contentUid, int $fieldIndex): string
    {
        $contentNumber = (int) substr($contentUid, -12);

        return sprintf('40000000-0000-%04d-%04d-%012d', $contentNumber, $fieldIndex, ($contentNumber * 100) + $fieldIndex);
    }

    private static function adminPasswordHash(): string
    {
        return password_hash(self::appSecret(), PASSWORD_BCRYPT, ['cost' => 4]);
    }

    private static function apiKeyHmacHash(string $plainKey): string
    {
        return hash_hmac('sha256', $plainKey, self::appSecret());
    }

    private static function encryptApiKey(string $plainKey): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plainKey,
            'aes-256-gcm',
            hash('sha256', self::appSecret(), true),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if (false === $ciphertext) {
            throw new RuntimeException('Unable to encrypt seeded API key.');
        }

        return 'v1.'.base64_encode($nonce).'.'.base64_encode($tag).'.'.base64_encode($ciphertext);
    }

    private static function appSecret(): string
    {
        $appSecret = $_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? null;

        if (!is_string($appSecret) || '' === $appSecret) {
            throw new RuntimeException('APP_SECRET must be available to seed protected test credentials.');
        }

        return $appSecret;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function __construct()
    {
    }
}
