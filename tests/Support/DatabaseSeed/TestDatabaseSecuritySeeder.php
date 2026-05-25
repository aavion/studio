<?php

declare(strict_types=1);

namespace App\Tests\Support\DatabaseSeed;

use App\Core\Access\AccessLevel;

final class TestDatabaseSecuritySeeder
{
    public static function seed(TestDatabaseSeedWriter $writer): void
    {
        self::seedAclGroups($writer);
        self::seedAdminUser($writer);
        self::seedApiKeys($writer);
    }

    private static function seedAclGroups(TestDatabaseSeedWriter $writer): void
    {
        $groups = [
            ['00000000-0000-0000-0000-000000000101', 'registered', ['en' => 'Registered', 'de' => 'Registriert'], AccessLevel::REGISTERED, true, true],
            ['00000000-0000-0000-0000-000000000103', 'editor', ['en' => 'Editor', 'de' => 'Redaktion'], AccessLevel::EDITOR, false, true],
            ['00000000-0000-0000-0000-000000000106', 'manager', ['en' => 'Manager', 'de' => 'Management'], AccessLevel::MANAGER, false, true],
            ['00000000-0000-0000-0000-000000000109', 'admin', ['en' => 'Admin', 'de' => 'Administration'], AccessLevel::ADMIN, true, false],
        ];

        foreach ($groups as $index => [$uid, $identifier, $name, $accessLevel, $locked, $allowEmpty]) {
            $writer->insert('acl_group', [
                'uid' => $uid,
                'identifier' => $identifier,
                'name' => $writer->json($name),
                'access_level' => $accessLevel,
                'locked' => $locked ? 1 : 0,
                'allow_empty' => $allowEmpty ? 1 : 0,
                'metadata' => $writer->json(['preset' => true]),
            ]);
            $writer->seedStateMarker(sprintf('00000000-0000-0000-0000-00000000091%d', $index), 'acl_group', $uid, 'created', 'test_seed', null, ['identifier' => $identifier]);
        }
    }

    private static function seedAdminUser(TestDatabaseSeedWriter $writer): void
    {
        $writer->insert('user_account', [
            'uid' => '00000000-0000-0000-0000-000000000201',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password_hash' => $writer->adminPasswordHash(),
            'profile' => $writer->json([
                'display_name' => 'Test Administrator',
                'locale' => 'en',
                'seed_password' => 'APP_SECRET',
            ]),
            'settings' => $writer->json(['language' => 'default']),
            'status' => 'active',
        ]);
        $writer->seedStateMarker('00000000-0000-0000-0000-000000000901', 'user_account', '00000000-0000-0000-0000-000000000201', 'created', 'test_seed');
        $writer->seedStateMarker('00000000-0000-0000-0000-000000000902', 'user_account', '00000000-0000-0000-0000-000000000201', 'password_changed', 'test_seed');
        $writer->seedStateMarker('00000000-0000-0000-0000-000000000903', 'user_account', '00000000-0000-0000-0000-000000000201', 'status_changed', 'test_seed', 'active');

        $writer->insert('user_acl_group', [
            'user_uid' => '00000000-0000-0000-0000-000000000201',
            'group_uid' => '00000000-0000-0000-0000-000000000109',
        ]);
    }

    private static function seedApiKeys(TestDatabaseSeedWriter $writer): void
    {
        foreach ([
            ['00000000-0000-0000-0000-000000000301', 'seedrw', 'test_seed_read_write_key', 'read_write', null],
            ['00000000-0000-0000-0000-000000000302', 'seedro', 'test_seed_read_only_key', 'read_only', null],
            ['00000000-0000-0000-0000-000000000303', 'seedrv', 'test_seed_revoked_key', 'revoked', TestDatabaseSeedWriter::NOW],
        ] as [$uid, $prefix, $plainKey, $status, $revokedAt]) {
            $writer->insert('api_key', [
                'uid' => $uid,
                'prefix' => $prefix,
                'hmac_hash' => $writer->apiKeyHmacHash($plainKey),
                'encrypted_key' => $writer->encryptApiKey($plainKey),
                'user_uid' => '00000000-0000-0000-0000-000000000201',
                'status' => $status,
                'created_at' => TestDatabaseSeedWriter::NOW,
                'revoked_at' => $revokedAt,
            ]);
        }
    }

    private function __construct()
    {
    }
}
