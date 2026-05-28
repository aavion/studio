<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Config\Config;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateSubjectType;
use Doctrine\DBAL\Connection;

final readonly class SetupDatabaseSeeder
{
    public function __construct(
        private SetupDatabaseConnectionFactory $connectionFactory = new SetupDatabaseConnectionFactory(),
        private SetupDefaultSeed $defaultSeed = new SetupDefaultSeed(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function seedDefaultSettings(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        $connection = $this->connection($projectDir, $databaseUrl, $input);
        $config = new Config($connection);
        $settings = $this->defaultSeed->configEntries($input);

        foreach ($settings as $setting) {
            $key = $setting['key'];

            if (!$config->set($key, $setting['value'], $setting['type'], modifiedBy: 'setup')) {
                throw SetupStepFailedException::fromMessage(Message::error(
                    MessageCode::CONFIG_WRITE_FAILED,
                    MessageKey::CONFIG_WRITE_FAILED,
                    ['%key%' => $key],
                    ['operation' => 'setup.seed_default_settings', 'config_key' => $key],
                ));
            }
        }

        return ['settings' => array_column($settings, 'key')];
    }

    /**
     * @return array<string, mixed>
     */
    public function seedAdminUser(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        $connection = $this->connection($projectDir, $databaseUrl, $input);
        $now = $this->now();

        foreach ($this->defaultSeed->aclGroups() as $group) {
            $groupUid = $this->upsertAclGroup(
                $connection,
                $group['uid'],
                $group['identifier'],
                $group['name'],
                $group['access_level'],
                $group['locked'],
                $group['allow_empty'],
            );
            $this->upsertStateMarker($connection, StateSubjectType::ACL_GROUP, $groupUid, StateMarkerKey::CREATED, $now, 'setup', null, ['identifier' => $group['identifier']]);
        }

        $userUid = $this->upsertAdmin($connection, $input, $now);
        $this->upsertStateMarker($connection, StateSubjectType::USER_ACCOUNT, $userUid, StateMarkerKey::CREATED, $now, 'setup');
        $this->upsertStateMarker($connection, StateSubjectType::USER_ACCOUNT, $userUid, StateMarkerKey::PASSWORD_CHANGED, $now, 'setup');
        $this->upsertStateMarker($connection, StateSubjectType::USER_ACCOUNT, $userUid, StateMarkerKey::STATUS_CHANGED, $now, 'setup', 'active');

        $this->ensureUserGroup($connection, $userUid, (string) $connection->fetchOne('SELECT uid FROM acl_group WHERE identifier = ?', [$this->defaultSeed->adminGroupIdentifier()]));

        return ['admin_username' => $input->adminUsername(), 'admin_email' => $input->adminEmail()];
    }

    /**
     * @return array<string, mixed>
     */
    public function seedInitialContent(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        $connection = $this->connection($projectDir, $databaseUrl, $input);
        $now = $this->now();
        $schema = $this->defaultSeed->contentSchema();
        $schemaVersion = $this->defaultSeed->contentSchemaVersion();
        $homeContent = $this->defaultSeed->homeContentItem();
        $homeRevision = $this->defaultSeed->homeContentRevision();

        $schemaUid = $this->upsertContentSchema($connection, $schema, $now);
        $schemaVersionUid = $this->upsertContentSchemaVersion($connection, $schemaVersion, $schemaUid, $schema['descriptions'], $now);
        $connection->update('content_schema', ['active_version_uid' => $schemaVersionUid], ['uid' => $schemaUid]);
        $contentUid = $this->upsertContentItem($connection, $homeContent, $schemaUid, $now);
        $revisionUid = $this->upsertContentRevision($connection, $homeRevision, $contentUid, $schemaUid, $schemaVersionUid);
        $this->replaceContentFields($connection, $revisionUid, $this->defaultSeed->homeContentFields($input));

        $connection->update('content_item', ['active_revision_uid' => $revisionUid], ['uid' => $contentUid]);
        $this->upsertStateMarker($connection, StateSubjectType::CONTENT_SCHEMA, $schemaUid, StateMarkerKey::CREATED, $now, 'setup', null, ['identifier' => $schema['identifier']]);
        $this->upsertStateMarker($connection, StateSubjectType::CONTENT_SCHEMA_VERSION, $schemaVersionUid, StateMarkerKey::ACTIVATED, $now, 'setup', '1', ['schema_uid' => $schemaUid]);
        $this->upsertStateMarker($connection, StateSubjectType::CONTENT_ITEM, $contentUid, StateMarkerKey::CREATED, $now, 'setup', null, ['slug' => $homeContent['slug']]);
        $this->upsertStateMarker($connection, StateSubjectType::CONTENT_ITEM, $contentUid, StateMarkerKey::PUBLISHED, $now, 'setup', 'published', ['revision_uid' => $revisionUid]);
        $this->upsertStateMarker($connection, StateSubjectType::CONTENT_REVISION, $revisionUid, StateMarkerKey::CREATED, $now, 'setup', null, ['content_uid' => $contentUid]);

        return ['schema' => $schema['identifier'], 'path' => $this->defaultSeed->homePath(), 'content_uid' => $contentUid];
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
     * @param array{uid: string, identifier: string, source: string, locked: bool, labels: array<string, string>, descriptions: array<string, string>} $schema
     */
    private function upsertContentSchema(Connection $connection, array $schema, string $now): string
    {
        $values = [
            'identifier' => $schema['identifier'],
            'source' => $schema['source'],
            'locked' => $schema['locked'] ? 1 : 0,
            'active_version_uid' => null,
            'labels' => json_encode($schema['labels'], JSON_THROW_ON_ERROR),
            'descriptions' => json_encode($schema['descriptions'], JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['seeded_by' => 'setup', 'updated_at' => $now], JSON_THROW_ON_ERROR),
        ];
        $existingUid = $connection->fetchOne('SELECT uid FROM content_schema WHERE identifier = ?', [$schema['identifier']]);

        if (is_string($existingUid) && '' !== $existingUid) {
            $connection->update('content_schema', $values, ['uid' => $existingUid]);

            return $existingUid;
        }

        $connection->insert('content_schema', ['uid' => $schema['uid'], ...$values]);

        return $schema['uid'];
    }

    /**
     * @param array{uid: string, version: int, title: array<string, string>, definition: array<string, mixed>} $schemaVersion
     * @param array<string, string> $description
     */
    private function upsertContentSchemaVersion(
        Connection $connection,
        array $schemaVersion,
        string $schemaUid,
        array $description,
        string $now,
    ): string {
        $definitionJson = json_encode($schemaVersion['definition'], JSON_THROW_ON_ERROR);
        $values = [
            'schema_uid' => $schemaUid,
            'version' => $schemaVersion['version'],
            'title' => json_encode($schemaVersion['title'], JSON_THROW_ON_ERROR),
            'description' => json_encode($description, JSON_THROW_ON_ERROR),
            'definition' => $definitionJson,
            'custom_twig' => null,
            'definition_hash' => hash('sha256', $definitionJson),
            'use_min_level' => null,
            'use_group_identifiers' => null,
            'edit_min_level' => null,
            'edit_group_identifiers' => null,
            'manage_min_level' => null,
            'manage_group_identifiers' => null,
            'metadata' => json_encode(['seeded_by' => 'setup', 'updated_at' => $now], JSON_THROW_ON_ERROR),
        ];
        $existingUid = $connection->fetchOne('SELECT uid FROM content_schema_version WHERE schema_uid = ? AND version = ?', [$schemaUid, $schemaVersion['version']]);

        if (is_string($existingUid) && '' !== $existingUid) {
            $connection->update('content_schema_version', $values, ['uid' => $existingUid]);

            return $existingUid;
        }

        $connection->insert('content_schema_version', ['uid' => $schemaVersion['uid'], ...$values]);

        return $schemaVersion['uid'];
    }

    /**
     * @param array{uid: string, slug: string, status: string, parent_uid: string, sort_order: int, schema_version: int, version: int, available_languages: list<string>, available_variants: list<string>, visibility: string, view_min_level: int, edit_min_level: int, manage_min_level: int, template_hint: string} $content
     */
    private function upsertContentItem(Connection $connection, array $content, string $schemaUid, string $now): string
    {
        $values = [
            'slug' => $content['slug'],
            'status' => $content['status'],
            'parent_uid' => $content['parent_uid'],
            'sort_order' => $content['sort_order'],
            'custom_url' => null,
            'redirect_target' => null,
            'schema_uid' => $schemaUid,
            'schema_version' => $content['schema_version'],
            'active_revision_uid' => null,
            'version' => $content['version'],
            'available_languages' => json_encode($content['available_languages'], JSON_THROW_ON_ERROR),
            'available_variants' => json_encode($content['available_variants'], JSON_THROW_ON_ERROR),
            'visibility' => $content['visibility'],
            'acl_restrictions' => json_encode([], JSON_THROW_ON_ERROR),
            'view_min_level' => $content['view_min_level'],
            'view_group_identifiers' => null,
            'edit_min_level' => $content['edit_min_level'],
            'edit_group_identifiers' => null,
            'manage_min_level' => $content['manage_min_level'],
            'manage_group_identifiers' => null,
            'metadata' => json_encode(['seeded_by' => 'setup', 'template_hint' => $content['template_hint'], 'updated_at' => $now], JSON_THROW_ON_ERROR),
        ];
        $existingUid = $connection->fetchOne('SELECT uid FROM content_item WHERE parent_uid = ? AND slug = ?', [$content['parent_uid'], $content['slug']]);

        if (is_string($existingUid) && '' !== $existingUid) {
            $connection->update('content_item', $values, ['uid' => $existingUid]);

            return $existingUid;
        }

        $connection->insert('content_item', ['uid' => $content['uid'], ...$values]);

        return $content['uid'];
    }

    /**
     * @param array{uid: string, version: int, change_summary: string} $revision
     */
    private function upsertContentRevision(
        Connection $connection,
        array $revision,
        string $contentUid,
        string $schemaUid,
        string $schemaVersionUid,
    ): string {
        $values = [
            'content_uid' => $contentUid,
            'version' => $revision['version'],
            'schema_uid' => $schemaUid,
            'schema_version_uid' => $schemaVersionUid,
            'change_summary' => $revision['change_summary'],
            'metadata' => json_encode(['seeded_by' => 'setup'], JSON_THROW_ON_ERROR),
        ];
        $existingUid = $connection->fetchOne('SELECT uid FROM content_revision WHERE content_uid = ? AND version = ?', [$contentUid, $revision['version']]);

        if (is_string($existingUid) && '' !== $existingUid) {
            $connection->update('content_revision', $values, ['uid' => $existingUid]);

            return $existingUid;
        }

        $connection->insert('content_revision', ['uid' => $revision['uid'], ...$values]);

        return $revision['uid'];
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     */
    private function replaceContentFields(Connection $connection, string $revisionUid, array $fields): void
    {
        $connection->delete('content_field_value', ['revision_uid' => $revisionUid]);
        $fieldIndex = 1;

        foreach ($fields as $fieldIdentifier => $localizedValues) {
            foreach ($localizedValues as $language => $fieldContent) {
                $connection->insert('content_field_value', [
                    'uid' => sprintf('20000000-0000-0000-0001-%012d', $fieldIndex),
                    'revision_uid' => $revisionUid,
                    'language' => $language,
                    'variant' => 'default',
                    'field_identifier' => $fieldIdentifier,
                    'field_content' => json_encode($fieldContent, JSON_THROW_ON_ERROR),
                ]);
                ++$fieldIndex;
            }
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
