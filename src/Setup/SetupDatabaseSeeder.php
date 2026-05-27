<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Access\AccessLevel;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Log\ConfigAuditLogPolicy;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Statistics\AccessStatisticsPolicy;
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
            [ConfigAuditLogPolicy::ENABLED_KEY, true, ConfigValueType::Boolean],
            [ConfigAuditLogPolicy::EVENTS_KEY, ConfigAuditLogPolicy::DEFAULT_CATEGORIES, ConfigValueType::Json],
            [AccessStatisticsPolicy::ENABLED_KEY, true, ConfigValueType::Boolean],
            [AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY, true, ConfigValueType::Boolean],
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
     * @return array<string, mixed>
     */
    public function seedInitialContent(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        $connection = $this->connection($projectDir, $databaseUrl, $input);
        $now = $this->now();
        $schemaUid = '10000000-0000-0000-0000-000000000001';
        $schemaVersionUid = '10000000-0000-0000-0000-000000000101';
        $contentUid = '20000000-0000-0000-0000-000000000001';
        $revisionUid = '20000000-0000-0000-0000-000000000101';

        $definition = [
            'fields' => [
                ['identifier' => 'title', 'type' => 'text', 'required' => true, 'localized' => true],
                ['identifier' => 'subtitle', 'type' => 'text', 'required' => true, 'localized' => true],
                ['identifier' => 'body', 'type' => 'rich_text', 'required' => true, 'localized' => true],
                ['identifier' => 'seo_title', 'type' => 'text', 'required' => false, 'localized' => true],
            ],
            'order' => ['title', 'subtitle', 'body', 'seo_title'],
        ];
        $description = [
            'en' => 'General pages with a rich text body.',
            'de' => 'Allgemeine Seiten mit Rich-Text-Inhalt.',
        ];

        $schemaUid = $this->upsertContentSchema($connection, $schemaUid, $description, $now);
        $schemaVersionUid = $this->upsertContentSchemaVersion($connection, $schemaVersionUid, $schemaUid, $definition, $description, $now);
        $connection->update('content_schema', ['active_version_uid' => $schemaVersionUid], ['uid' => $schemaUid]);
        $contentUid = $this->upsertContentItem($connection, $contentUid, $schemaUid, $now);
        $revisionUid = $this->upsertContentRevision($connection, $revisionUid, $contentUid, $schemaUid, $schemaVersionUid);
        $this->replaceContentFields($connection, $revisionUid, [
            'title' => [
                'en' => $input->siteTitle(),
                'de' => $input->siteTitle(),
            ],
            'subtitle' => [
                'en' => 'Your new Studio site is ready.',
                'de' => 'Deine neue Studio-Seite ist bereit.',
            ],
            'body' => [
                'en' => ['html' => '<p>This placeholder page was created during setup and can be replaced in the editor.</p>'],
                'de' => ['html' => '<p>Diese Platzhalterseite wurde waehrend des Setups angelegt und kann im Editor ersetzt werden.</p>'],
            ],
            'seo_title' => [
                'en' => $input->siteTitle(),
                'de' => $input->siteTitle(),
            ],
        ]);

        $connection->update('content_item', ['active_revision_uid' => $revisionUid], ['uid' => $contentUid]);
        $this->upsertStateMarker($connection, StateSubjectType::CONTENT_SCHEMA, $schemaUid, StateMarkerKey::CREATED, $now, 'setup', null, ['identifier' => 'static_page']);
        $this->upsertStateMarker($connection, StateSubjectType::CONTENT_SCHEMA_VERSION, $schemaVersionUid, StateMarkerKey::ACTIVATED, $now, 'setup', '1', ['schema_uid' => $schemaUid]);
        $this->upsertStateMarker($connection, StateSubjectType::CONTENT_ITEM, $contentUid, StateMarkerKey::CREATED, $now, 'setup', null, ['slug' => 'home']);
        $this->upsertStateMarker($connection, StateSubjectType::CONTENT_ITEM, $contentUid, StateMarkerKey::PUBLISHED, $now, 'setup', 'published', ['revision_uid' => $revisionUid]);
        $this->upsertStateMarker($connection, StateSubjectType::CONTENT_REVISION, $revisionUid, StateMarkerKey::CREATED, $now, 'setup', null, ['content_uid' => $contentUid]);

        return ['schema' => 'static_page', 'path' => '/home', 'content_uid' => $contentUid];
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
     * @param array<string, string> $description
     */
    private function upsertContentSchema(Connection $connection, string $schemaUid, array $description, string $now): string
    {
        $values = [
            'identifier' => 'static_page',
            'source' => 'setup',
            'locked' => 1,
            'active_version_uid' => null,
            'labels' => json_encode(['en' => 'Static page', 'de' => 'Statische Seite'], JSON_THROW_ON_ERROR),
            'descriptions' => json_encode($description, JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['seeded_by' => 'setup', 'updated_at' => $now], JSON_THROW_ON_ERROR),
        ];
        $existingUid = $connection->fetchOne('SELECT uid FROM content_schema WHERE identifier = ?', ['static_page']);

        if (is_string($existingUid) && '' !== $existingUid) {
            $connection->update('content_schema', $values, ['uid' => $existingUid]);

            return $existingUid;
        }

        $connection->insert('content_schema', ['uid' => $schemaUid, ...$values]);

        return $schemaUid;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, string> $description
     */
    private function upsertContentSchemaVersion(
        Connection $connection,
        string $versionUid,
        string $schemaUid,
        array $definition,
        array $description,
        string $now,
    ): string {
        $definitionJson = json_encode($definition, JSON_THROW_ON_ERROR);
        $values = [
            'schema_uid' => $schemaUid,
            'version' => 1,
            'title' => json_encode(['en' => 'Static page schema', 'de' => 'Schema fuer statische Seiten'], JSON_THROW_ON_ERROR),
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
        $existingUid = $connection->fetchOne('SELECT uid FROM content_schema_version WHERE schema_uid = ? AND version = ?', [$schemaUid, 1]);

        if (is_string($existingUid) && '' !== $existingUid) {
            $connection->update('content_schema_version', $values, ['uid' => $existingUid]);

            return $existingUid;
        }

        $connection->insert('content_schema_version', ['uid' => $versionUid, ...$values]);

        return $versionUid;
    }

    private function upsertContentItem(Connection $connection, string $contentUid, string $schemaUid, string $now): string
    {
        $values = [
            'slug' => 'home',
            'status' => 'published',
            'parent_uid' => '/',
            'sort_order' => 10,
            'custom_url' => null,
            'redirect_target' => null,
            'schema_uid' => $schemaUid,
            'schema_version' => 1,
            'active_revision_uid' => null,
            'version' => 1,
            'available_languages' => json_encode(['en', 'de'], JSON_THROW_ON_ERROR),
            'available_variants' => json_encode(['default'], JSON_THROW_ON_ERROR),
            'visibility' => 'public',
            'acl_restrictions' => json_encode([], JSON_THROW_ON_ERROR),
            'view_min_level' => AccessLevel::PUBLIC,
            'view_group_identifiers' => null,
            'edit_min_level' => AccessLevel::EDITOR,
            'edit_group_identifiers' => null,
            'manage_min_level' => AccessLevel::MANAGER,
            'manage_group_identifiers' => null,
            'metadata' => json_encode(['seeded_by' => 'setup', 'template_hint' => 'home', 'updated_at' => $now], JSON_THROW_ON_ERROR),
        ];
        $existingUid = $connection->fetchOne('SELECT uid FROM content_item WHERE parent_uid = ? AND slug = ?', ['/', 'home']);

        if (is_string($existingUid) && '' !== $existingUid) {
            $connection->update('content_item', $values, ['uid' => $existingUid]);

            return $existingUid;
        }

        $connection->insert('content_item', ['uid' => $contentUid, ...$values]);

        return $contentUid;
    }

    private function upsertContentRevision(
        Connection $connection,
        string $revisionUid,
        string $contentUid,
        string $schemaUid,
        string $schemaVersionUid,
    ): string {
        $values = [
            'content_uid' => $contentUid,
            'version' => 1,
            'schema_uid' => $schemaUid,
            'schema_version_uid' => $schemaVersionUid,
            'change_summary' => 'Seeded setup homepage.',
            'metadata' => json_encode(['seeded_by' => 'setup'], JSON_THROW_ON_ERROR),
        ];
        $existingUid = $connection->fetchOne('SELECT uid FROM content_revision WHERE content_uid = ? AND version = ?', [$contentUid, 1]);

        if (is_string($existingUid) && '' !== $existingUid) {
            $connection->update('content_revision', $values, ['uid' => $existingUid]);

            return $existingUid;
        }

        $connection->insert('content_revision', ['uid' => $revisionUid, ...$values]);

        return $revisionUid;
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
