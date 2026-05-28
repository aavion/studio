<?php

declare(strict_types=1);

namespace App\Tests\Support\DatabaseSeed;

use App\Setup\SetupDefaultSeed;

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
        $groups = (new SetupDefaultSeed())->aclGroups();

        foreach ($groups as $index => $group) {
            $writer->insert('acl_group', [
                'uid' => $group['uid'],
                'identifier' => $group['identifier'],
                'name' => $writer->json($group['name']),
                'access_level' => $group['access_level'],
                'locked' => $group['locked'] ? 1 : 0,
                'allow_empty' => $group['allow_empty'] ? 1 : 0,
                'metadata' => $writer->json(['preset' => true]),
            ]);
            $writer->seedStateMarker(sprintf('00000000-0000-0000-0000-00000000091%d', $index), 'acl_group', $group['uid'], 'created', 'test_seed', null, ['identifier' => $group['identifier']]);
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
            'group_uid' => self::defaultSeedGroupUid((new SetupDefaultSeed())->adminGroupIdentifier()),
        ]);
    }

    private static function defaultSeedGroupUid(string $identifier): string
    {
        foreach ((new SetupDefaultSeed())->aclGroups() as $group) {
            if ($identifier === $group['identifier']) {
                return $group['uid'];
            }
        }

        throw new \LogicException(sprintf('Default ACL group "%s" is not defined.', $identifier));
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
