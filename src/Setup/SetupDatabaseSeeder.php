<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Access\AccessLevel;
use App\Core\Config\ConfigValueType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final readonly class SetupDatabaseSeeder
{
    /**
     * @return array<string, mixed>
     */
    public function seedDefaultSettings(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        $connection = $this->connection($projectDir, $databaseUrl);
        $now = $this->now();

        $this->upsertConfig($connection, 'site.title', $input->siteTitle(), ConfigValueType::String, $now);
        $this->upsertConfig($connection, 'site.url', $input->defaultUri(), ConfigValueType::String, $now);
        $this->upsertConfig($connection, 'localization.default_language', $input->language(), ConfigValueType::String, $now);
        $this->upsertConfig($connection, 'user.default_acl_group', 'registered', ConfigValueType::String, $now);

        return ['settings' => ['site.title', 'site.url', 'localization.default_language', 'user.default_acl_group']];
    }

    /**
     * @return array<string, mixed>
     */
    public function seedAdminUser(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        $connection = $this->connection($projectDir, $databaseUrl);
        $now = $this->now();
        $groups = [
            ['00000000-0000-0000-0000-000000000102', 'registered', ['en' => 'Registered', 'de' => 'Registriert'], AccessLevel::REGISTERED, true, true],
            ['00000000-0000-0000-0000-000000000103', 'editor', ['en' => 'Editor', 'de' => 'Editor'], AccessLevel::EDITOR, false, true],
            ['00000000-0000-0000-0000-000000000104', 'manager', ['en' => 'Manager', 'de' => 'Manager'], AccessLevel::MANAGER, false, true],
            ['00000000-0000-0000-0000-000000000105', 'admin', ['en' => 'Admin', 'de' => 'Admin'], AccessLevel::ADMIN, true, false],
        ];

        foreach ($groups as [$uid, $identifier, $name, $accessLevel, $locked, $allowEmpty]) {
            $this->upsertAclGroup($connection, $uid, $identifier, $name, $accessLevel, $locked, $allowEmpty);
        }

        $userUid = $this->upsertAdmin($connection, $input, $now);

        $this->ensureUserGroup($connection, $userUid, (string) $connection->fetchOne('SELECT uid FROM acl_group WHERE identifier = ?', ['admin']));

        return ['admin_username' => $input->adminUsername(), 'admin_email' => $input->adminEmail()];
    }

    private function upsertConfig(Connection $connection, string $key, mixed $value, ConfigValueType $type, string $now): void
    {
        $values = [
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'value_type' => $type->value,
            'sensitive' => 0,
            'modified_at' => $now,
            'modified_by' => 'setup',
        ];

        $connection->fetchOne('SELECT config_key FROM config_entry WHERE config_key = ?', [$key])
            ? $connection->update('config_entry', $values, ['config_key' => $key])
            : $connection->insert('config_entry', ['config_key' => $key, ...$values]);
    }

    /**
     * @param array<string, string> $name
     */
    private function upsertAclGroup(
        Connection $connection,
        string $uid,
        string $identifier,
        array $name,
        int $accessLevel,
        bool $locked,
        bool $allowEmpty,
    ): void {
        $values = [
            'uid' => $uid,
            'identifier' => $identifier,
            'name' => json_encode($name, JSON_THROW_ON_ERROR),
            'access_level' => $accessLevel,
            'locked' => $locked ? 1 : 0,
            'allow_empty' => $allowEmpty ? 1 : 0,
            'metadata' => json_encode(['seeded_by' => 'setup'], JSON_THROW_ON_ERROR),
        ];

        $connection->fetchOne('SELECT uid FROM acl_group WHERE identifier = ?', [$identifier])
            ? $connection->update('acl_group', $values, ['identifier' => $identifier])
            : $connection->insert('acl_group', $values);
    }

    private function upsertAdmin(Connection $connection, SetupInput $input, string $now): string
    {
        $existingUid = $connection->fetchOne('SELECT uid FROM user_account WHERE username = ?', [$input->adminUsername()]);
        $values = [
            'username' => $input->adminUsername(),
            'email' => $input->adminEmail(),
            'password_hash' => password_hash($input->adminPassword(), PASSWORD_DEFAULT),
            'profile' => json_encode(['created_by' => 'setup', 'updated_at' => $now], JSON_THROW_ON_ERROR),
        ];

        if (is_string($existingUid) && '' !== $existingUid) {
            $connection->update('user_account', $values, ['uid' => $existingUid]);

            return $existingUid;
        }

        $uid = $this->uuid();
        $connection->insert('user_account', ['uid' => $uid, ...$values]);

        return $uid;
    }

    private function ensureUserGroup(Connection $connection, string $userUid, string $groupUid): void
    {
        if (!$connection->fetchOne('SELECT user_uid FROM user_acl_group WHERE user_uid = ? AND group_uid = ?', [$userUid, $groupUid])) {
            $connection->insert('user_acl_group', ['user_uid' => $userUid, 'group_uid' => $groupUid]);
        }
    }

    private function connection(string $projectDir, string $databaseUrl): Connection
    {
        return DriverManager::getConnection($this->connectionParameters(str_replace('%kernel.project_dir%', $projectDir, $databaseUrl)));
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionParameters(string $databaseUrl): array
    {
        if (str_starts_with($databaseUrl, 'sqlite:///')) {
            return ['driver' => 'pdo_sqlite', 'path' => preg_replace('#^sqlite:///#', '/', $databaseUrl)];
        }

        $scheme = (string) parse_url($databaseUrl, PHP_URL_SCHEME);

        return [
            'url' => $databaseUrl,
            'driver' => match ($scheme) {
                'mysql', 'mariadb' => 'pdo_mysql',
                'pgsql', 'postgres', 'postgresql' => 'pdo_pgsql',
                default => throw new SetupStepFailedException(sprintf('Unsupported database URL scheme "%s".', $scheme)),
            },
        ];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
