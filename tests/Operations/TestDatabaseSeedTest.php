<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use App\Tests\Support\FilesystemTestHelper;
use PDO;
use PHPUnit\Framework\TestCase;

final class TestDatabaseSeedTest extends TestCase
{
    use FilesystemTestHelper;

    private PDO $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required for seeded test database verification.');
        }

        $this->pdo = new PDO('sqlite:'.$this->projectRoot().'/var/test/test.db');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function testItSeedsAclGroupsAndAdminUser(): void
    {
        $groups = $this->pdo
            ->query('SELECT identifier, access_level FROM acl_group ORDER BY access_level')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        self::assertSame([
            'public' => 0,
            'editor' => 3,
            'manager' => 6,
            'admin' => 9,
        ], array_map('intval', $groups));

        $adminGroups = $this->pdo
            ->query("SELECT g.identifier FROM acl_group g INNER JOIN user_acl_group ug ON ug.group_uid = g.uid INNER JOIN user_account u ON u.uid = ug.user_uid WHERE u.username = 'admin' ORDER BY g.access_level")
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame(['editor', 'manager', 'admin'], $adminGroups);
    }

    public function testItUsesAppSecretAsSeededAdminPassword(): void
    {
        $hash = $this->pdo
            ->query("SELECT password_hash FROM user_account WHERE username = 'admin'")
            ->fetchColumn();

        self::assertIsString($hash);
        self::assertTrue(password_verify((string) $_SERVER['APP_SECRET'], $hash));
    }

    public function testItSeedsApiKeysForEachLifecycleStatus(): void
    {
        $apiKeys = $this->pdo
            ->query("SELECT prefix, hmac_hash, encrypted_key, status, revoked_at FROM api_key WHERE user_uid = '00000000-0000-0000-0000-000000000201' ORDER BY prefix")
            ->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(3, $apiKeys);
        self::assertSame('seedro', $apiKeys[0]['prefix']);
        self::assertSame(hash_hmac('sha256', 'test_seed_read_only_key', (string) $_SERVER['APP_SECRET']), $apiKeys[0]['hmac_hash']);
        self::assertSame('test_seed_read_only_key', self::decryptSeededApiKey((string) $apiKeys[0]['encrypted_key']));
        self::assertSame('read_only', $apiKeys[0]['status']);
        self::assertNull($apiKeys[0]['revoked_at']);

        self::assertSame('seedrv', $apiKeys[1]['prefix']);
        self::assertSame(hash_hmac('sha256', 'test_seed_revoked_key', (string) $_SERVER['APP_SECRET']), $apiKeys[1]['hmac_hash']);
        self::assertSame('test_seed_revoked_key', self::decryptSeededApiKey((string) $apiKeys[1]['encrypted_key']));
        self::assertSame('revoked', $apiKeys[1]['status']);
        self::assertSame('2026-05-23 21:00:00', $apiKeys[1]['revoked_at']);

        self::assertSame('seedrw', $apiKeys[2]['prefix']);
        self::assertSame(hash_hmac('sha256', 'test_seed_read_write_key', (string) $_SERVER['APP_SECRET']), $apiKeys[2]['hmac_hash']);
        self::assertSame('test_seed_read_write_key', self::decryptSeededApiKey((string) $apiKeys[2]['encrypted_key']));
        self::assertSame('read_write', $apiKeys[2]['status']);
        self::assertNull($apiKeys[2]['revoked_at']);
    }

    public function testItSeedsActivePresetSchemas(): void
    {
        $schemas = $this->pdo
            ->query('SELECT identifier, active_version_uid FROM content_schema ORDER BY identifier')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        self::assertArrayHasKey('article', $schemas);
        self::assertArrayHasKey('static_page', $schemas);
        self::assertNotNull($schemas['article']);
        self::assertNotNull($schemas['static_page']);

        $definitionJson = $this->pdo
            ->query("SELECT definition FROM content_schema_version WHERE uid = '10000000-0000-0000-0000-000000000101'")
            ->fetchColumn();
        $definition = json_decode((string) $definitionJson, true, flags: JSON_THROW_ON_ERROR);
        $fieldIdentifiers = array_column($definition['fields'], 'identifier');

        self::assertContains('title', $fieldIdentifiers);
        self::assertContains('subtitle', $fieldIdentifiers);
        self::assertContains('body', $fieldIdentifiers);
    }

    public function testItSeedsPublishedContentWithActiveRevisionsAndFields(): void
    {
        $content = $this->pdo
            ->query("SELECT slug, custom_url, active_revision_uid FROM content_item WHERE slug = 'home'")
            ->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($content);
        self::assertSame('/', $content['custom_url']);
        self::assertNotNull($content['active_revision_uid']);

        $titleJson = $this->pdo
            ->query("SELECT fv.field_content FROM content_field_value fv INNER JOIN content_item ci ON ci.active_revision_uid = fv.revision_uid WHERE ci.slug = 'home' AND fv.language = 'en' AND fv.variant = 'default' AND fv.field_identifier = 'title'")
            ->fetchColumn();

        self::assertSame('Welcome to Studio', json_decode((string) $titleJson, true, flags: JSON_THROW_ON_ERROR));

        $articleFields = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM content_field_value fv INNER JOIN content_item ci ON ci.active_revision_uid = fv.revision_uid WHERE ci.slug = 'first-update'")
            ->fetchColumn();

        self::assertGreaterThanOrEqual(10, $articleFields);
    }

    public function testItSeedsMainNavigation(): void
    {
        $menuItems = $this->pdo
            ->query("SELECT target_value FROM site_menu_item WHERE menu_uid = '30000000-0000-0000-0000-000000000001' ORDER BY sort_order")
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame(['/', '/about', '/news/first-update'], $menuItems);
    }

    private static function decryptSeededApiKey(string $payload): string
    {
        $parts = explode('.', $payload);

        self::assertCount(4, $parts);
        self::assertSame('v1', $parts[0]);

        $plaintext = openssl_decrypt(
            self::decodeSeedPayloadPart($parts[3]),
            'aes-256-gcm',
            hash('sha256', (string) $_SERVER['APP_SECRET'], true),
            OPENSSL_RAW_DATA,
            self::decodeSeedPayloadPart($parts[1]),
            self::decodeSeedPayloadPart($parts[2]),
        );

        self::assertIsString($plaintext);

        return $plaintext;
    }

    private static function decodeSeedPayloadPart(string $value): string
    {
        $decoded = base64_decode($value, true);

        self::assertIsString($decoded);

        return $decoded;
    }
}
