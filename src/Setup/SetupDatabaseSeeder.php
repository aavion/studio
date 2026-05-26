<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Access\AccessLevel;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateSubjectType;
use Doctrine\DBAL\Connection;

final readonly class SetupDatabaseSeeder
{
    public function __construct(private SetupDatabaseConnectionFactory $connectionFactory = new SetupDatabaseConnectionFactory())
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function seedDefaultSettings(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        $connection = $this->connection($projectDir, $databaseUrl, $input);
        $config = new Config($connection);
        $settings = [
            ['site.title', $input->siteTitle(), ConfigValueType::String],
            ['site.url', $input->defaultUri(), ConfigValueType::String],
            ['localization.default_language', $input->language(), ConfigValueType::String],
            ['localization.route_prefixes_enabled', false, ConfigValueType::Boolean],
            ['content.home_path', '/home', ConfigValueType::String],
            ['user.default_acl_group', 'registered', ConfigValueType::String],
            ['user.menu.enabled', true, ConfigValueType::Boolean],
            ['user.menu.sort_order', 900, ConfigValueType::Integer],
            ['user.registration.enabled', false, ConfigValueType::Boolean],
        ];

        foreach ($settings as [$key, $value, $type]) {
            if (!$config->set($key, $value, $type, modifiedBy: 'setup')) {
                throw SetupStepFailedException::fromMessage(Message::error(
                    MessageCode::CONFIG_WRITE_FAILED,
                    MessageKey::CONFIG_WRITE_FAILED,
                    ['%key%' => $key],
                    ['operation' => 'setup.seed_default_settings', 'config_key' => $key],
                ));
            }
        }

        return ['settings' => array_column($settings, 0)];
    }

    /**
     * @return array<string, mixed>
     */
    public function seedAdminUser(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        $connection = $this->connection($projectDir, $databaseUrl, $input);
        $now = $this->now();
        $groups = [
            ['00000000-0000-0000-0000-000000000102', 'registered', ['en' => 'Registered', 'de' => 'Registriert'], AccessLevel::REGISTERED, true, true],
            ['00000000-0000-0000-0000-000000000103', 'editor', ['en' => 'Editor', 'de' => 'Editor'], AccessLevel::EDITOR, false, true],
            ['00000000-0000-0000-0000-000000000104', 'manager', ['en' => 'Manager', 'de' => 'Manager'], AccessLevel::MANAGER, false, true],
            ['00000000-0000-0000-0000-000000000105', 'admin', ['en' => 'Admin', 'de' => 'Admin'], AccessLevel::ADMIN, true, false],
        ];

        foreach ($groups as [$uid, $identifier, $name, $accessLevel, $locked, $allowEmpty]) {
            $groupUid = $this->upsertAclGroup($connection, $uid, $identifier, $name, $accessLevel, $locked, $allowEmpty);
            $this->upsertStateMarker($connection, StateSubjectType::ACL_GROUP, $groupUid, StateMarkerKey::CREATED, $now, 'setup', null, ['identifier' => $identifier]);
        }

        $userUid = $this->upsertAdmin($connection, $input, $now);
        $this->upsertStateMarker($connection, StateSubjectType::USER_ACCOUNT, $userUid, StateMarkerKey::CREATED, $now, 'setup');
        $this->upsertStateMarker($connection, StateSubjectType::USER_ACCOUNT, $userUid, StateMarkerKey::PASSWORD_CHANGED, $now, 'setup');
        $this->upsertStateMarker($connection, StateSubjectType::USER_ACCOUNT, $userUid, StateMarkerKey::STATUS_CHANGED, $now, 'setup', 'active');

        $this->ensureUserGroup($connection, $userUid, (string) $connection->fetchOne('SELECT uid FROM acl_group WHERE identifier = ?', ['admin']));

        return ['admin_username' => $input->adminUsername(), 'admin_email' => $input->adminEmail()];
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
    ): string {
        $values = [
            'identifier' => $identifier,
            'name' => json_encode($name, JSON_THROW_ON_ERROR),
            'access_level' => $accessLevel,
            'locked' => $locked ? 1 : 0,
            'allow_empty' => $allowEmpty ? 1 : 0,
            'metadata' => json_encode(['seeded_by' => 'setup'], JSON_THROW_ON_ERROR),
        ];
        $existingUid = $connection->fetchOne('SELECT uid FROM acl_group WHERE identifier = ?', [$identifier]);

        if (is_string($existingUid) && '' !== $existingUid) {
            $connection->update('acl_group', $values, ['uid' => $existingUid]);

            return $existingUid;
        }

        $connection->insert('acl_group', ['uid' => $uid, ...$values]);

        return $uid;
    }

    private function upsertAdmin(Connection $connection, SetupInput $input, string $now): string
    {
        $existingUid = $connection->fetchOne('SELECT uid FROM user_account WHERE username = ?', [$input->adminUsername()]);
        $values = [
            'username' => $input->adminUsername(),
            'email' => $input->adminEmail(),
            'password_hash' => password_hash($input->adminPassword(), PASSWORD_DEFAULT),
            'profile' => json_encode(['created_by' => 'setup', 'updated_at' => $now], JSON_THROW_ON_ERROR),
            'settings' => json_encode(['language' => 'default'], JSON_THROW_ON_ERROR),
            'status' => 'active',
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

    /**
     * @param array<string, mixed> $metadata
     */
    private function upsertStateMarker(
        Connection $connection,
        string $subjectType,
        string $subjectUid,
        string $markerKey,
        string $markerAt,
        ?string $markerBy = null,
        ?string $markerValue = null,
        array $metadata = [],
    ): void {
        $values = [
            'marker_at' => $markerAt,
            'marker_by' => $markerBy,
            'marker_value' => $markerValue,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ];
        $where = [
            'subject_type' => $subjectType,
            'subject_uid' => $subjectUid,
            'marker_key' => $markerKey,
        ];

        $connection->fetchOne(
            'SELECT uid FROM state_marker WHERE subject_type = ? AND subject_uid = ? AND marker_key = ?',
            [$subjectType, $subjectUid, $markerKey],
        )
            ? $connection->update('state_marker', $values, $where)
            : $connection->insert('state_marker', ['uid' => $this->uuid(), ...$where, ...$values]);
    }

    private function connection(string $projectDir, string $databaseUrl, SetupInput $input): Connection
    {
        return $this->connectionFactory->create($projectDir, $databaseUrl, $input->appEnv());
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
