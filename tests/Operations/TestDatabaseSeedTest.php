<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use App\Setup\DatabaseDriver;
use App\Setup\SetupDefaultSeed;
use App\Setup\SetupInput;
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
        $seed = new SetupDefaultSeed();
        $groups = $this->pdo
            ->query('SELECT identifier, access_level, locked, allow_empty FROM acl_group ORDER BY access_level')
            ->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame(array_map(static fn (array $group): array => [
            'identifier' => $group['identifier'],
            'access_level' => $group['access_level'],
            'locked' => $group['locked'] ? 1 : 0,
            'allow_empty' => $group['allow_empty'] ? 1 : 0,
        ], $seed->aclGroups()), array_map(static fn (array $row): array => [
            'identifier' => $row['identifier'],
            'access_level' => (int) $row['access_level'],
            'locked' => (int) $row['locked'],
            'allow_empty' => (int) $row['allow_empty'],
        ], $groups));

        $adminGroups = $this->pdo
            ->query("SELECT g.identifier FROM acl_group g INNER JOIN user_acl_group ug ON ug.group_uid = g.uid INNER JOIN user_account u ON u.uid = ug.user_uid WHERE u.username = 'admin' ORDER BY g.access_level")
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame([$seed->adminGroupIdentifier()], $adminGroups);

        $defaultAclGroup = $this->pdo
            ->query("SELECT value FROM config_entry WHERE config_key = 'user.default_acl_group'")
            ->fetchColumn();

        self::assertSame($seed->configMap($this->setupSeedInput())['user.default_acl_group'], json_decode((string) $defaultAclGroup, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testItUsesAppSecretAsSeededAdminPassword(): void
    {
        $user = $this->pdo
            ->query("SELECT password_hash, settings, status, uid FROM user_account WHERE username = 'admin'")
            ->fetch(PDO::FETCH_ASSOC);
        $markers = $this->pdo
            ->query("SELECT marker_key, marker_value FROM state_marker WHERE subject_type = 'user_account' AND subject_uid = '00000000-0000-0000-0000-000000000201' ORDER BY marker_key")
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        self::assertIsArray($user);
        self::assertTrue(password_verify((string) $_SERVER['APP_SECRET'], (string) $user['password_hash']));
        self::assertSame(['language' => 'default'], json_decode((string) $user['settings'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('active', $user['status']);
        self::assertSame([
            'created' => null,
            'password_changed' => null,
            'status_changed' => 'active',
        ], $markers);
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
        $seed = new SetupDefaultSeed();
        $schemas = $this->pdo
            ->query('SELECT identifier, active_version_uid FROM content_schema ORDER BY identifier')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        self::assertArrayHasKey('article', $schemas);
        self::assertArrayHasKey($seed->contentSchema()['identifier'], $schemas);
        self::assertNotNull($schemas['article']);
        self::assertNotNull($schemas[$seed->contentSchema()['identifier']]);

        $definitionJson = $this->pdo
            ->query(sprintf("SELECT definition FROM content_schema_version WHERE uid = '%s'", $seed->contentSchemaVersion()['uid']))
            ->fetchColumn();
        $definition = json_decode((string) $definitionJson, true, flags: JSON_THROW_ON_ERROR);
        $fieldIdentifiers = array_column($definition['fields'], 'identifier');

        self::assertSame(
            array_column($seed->contentSchemaVersion()['definition']['fields'], 'identifier'),
            $fieldIdentifiers,
        );
    }

    public function testItSeedsPublishedContentWithActiveRevisionsAndFields(): void
    {
        $seed = new SetupDefaultSeed();
        $content = $this->pdo
            ->query(sprintf("SELECT slug, custom_url, active_revision_uid FROM content_item WHERE slug = '%s'", $seed->homeContentItem()['slug']))
            ->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($content);
        self::assertNull($content['custom_url']);
        self::assertNotNull($content['active_revision_uid']);

        $homePath = $this->pdo
            ->query("SELECT value FROM config_entry WHERE config_key = 'content.home_path'")
            ->fetchColumn();

        self::assertSame($seed->homePath(), json_decode((string) $homePath, true, flags: JSON_THROW_ON_ERROR));

        $titleJson = $this->pdo
            ->query(sprintf("SELECT fv.field_content FROM content_field_value fv INNER JOIN content_item ci ON ci.active_revision_uid = fv.revision_uid WHERE ci.slug = '%s' AND fv.language = 'en' AND fv.variant = 'default' AND fv.field_identifier = 'title'", $seed->homeContentItem()['slug']))
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

    private function setupSeedInput(): SetupInput
    {
        return new SetupInput(
            appEnv: 'test',
            language: 'en',
            siteTitle: 'Test Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'sqlite:///%kernel.project_dir%/var/test/test.db',
            adminUsername: 'admin',
            adminPassword: (string) ($_SERVER['APP_SECRET'] ?? 'test-secret'),
            adminEmail: 'admin@example.test',
        );
    }
}
