<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\State\StateMarkerKey;
use App\Core\State\StateSubjectType;
use Doctrine\DBAL\Connection;

final readonly class SetupInitialContentSeeder
{
    public function __construct(
        private SetupDatabaseConnectionFactory $connectionFactory = new SetupDatabaseConnectionFactory(),
        private SetupDefaultSeed $defaultSeed = new SetupDefaultSeed(),
        private SetupStateMarkerWriter $stateMarkers = new SetupStateMarkerWriter(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function seed(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        $connection = $this->connectionFactory->create($projectDir, $databaseUrl, $input->appEnv());
        $now = gmdate('Y-m-d H:i:s');
        $schema = $this->defaultSeed->contentSchema();
        $schemaVersion = $this->defaultSeed->contentSchemaVersion();
        $homeContent = $this->defaultSeed->homeContentItem([$input->language()]);
        $homeRevision = $this->defaultSeed->homeContentRevision();

        $schemaUid = $this->upsertContentSchema($connection, $schema, $now);
        $schemaVersionUid = $this->upsertContentSchemaVersion($connection, $schemaVersion, $schemaUid, $schema['descriptions'], $now);
        $connection->update('content_schema', ['active_version_uid' => $schemaVersionUid], ['uid' => $schemaUid]);
        $contentUid = $this->upsertContentItem($connection, $homeContent, $schemaUid, $now);
        $revisionUid = $this->upsertContentRevision($connection, $homeRevision, $contentUid, $schemaUid, $schemaVersionUid);
        $this->replaceContentFields($connection, $revisionUid, $this->defaultSeed->homeContentFields($input));

        $connection->update('content_item', ['active_revision_uid' => $revisionUid], ['uid' => $contentUid]);
        $this->stateMarkers->upsert($connection, StateSubjectType::CONTENT_SCHEMA, $schemaUid, StateMarkerKey::CREATED, $now, 'setup', null, ['identifier' => $schema['identifier']]);
        $this->stateMarkers->upsert($connection, StateSubjectType::CONTENT_SCHEMA_VERSION, $schemaVersionUid, StateMarkerKey::ACTIVATED, $now, 'setup', '1', ['schema_uid' => $schemaUid]);
        $this->stateMarkers->upsert($connection, StateSubjectType::CONTENT_ITEM, $contentUid, StateMarkerKey::CREATED, $now, 'setup', null, ['slug' => $homeContent['slug']]);
        $this->stateMarkers->upsert($connection, StateSubjectType::CONTENT_ITEM, $contentUid, StateMarkerKey::PUBLISHED, $now, 'setup', 'published', ['revision_uid' => $revisionUid]);
        $this->stateMarkers->upsert($connection, StateSubjectType::CONTENT_REVISION, $revisionUid, StateMarkerKey::CREATED, $now, 'setup', null, ['content_uid' => $contentUid]);

        return ['schema' => $schema['identifier'], 'path' => $this->defaultSeed->homePath(), 'content_uid' => $contentUid];
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
        $values = [
            'schema_uid' => $schemaUid,
            'version' => $schemaVersion['version'],
            'title' => json_encode($schemaVersion['title'], JSON_THROW_ON_ERROR),
            'description' => json_encode($description, JSON_THROW_ON_ERROR),
            'definition' => json_encode($schemaVersion['definition'], JSON_THROW_ON_ERROR),
            'custom_twig' => null,
            'definition_hash' => $this->definitionHash($schemaVersion['title'], $description, $schemaVersion['definition'], null),
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
     * @param array<string, string> $title
     * @param array<string, string> $description
     * @param array<string, mixed> $definition
     */
    private function definitionHash(array $title, array $description, array $definition, ?string $customTwig): string
    {
        return hash('sha256', json_encode([
            'title' => $title,
            'description' => $description,
            'definition' => $definition,
            'custom_twig' => $customTwig,
        ], JSON_THROW_ON_ERROR));
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
                    'uid' => sprintf('20000000-0000-7000-8001-%012d', $fieldIndex),
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
}
