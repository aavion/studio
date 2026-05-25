<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\DBAL\Connection;

trait PublicContentTestDatabaseTrait
{
    private function configureLocalizedRoutes(Connection $connection, bool $enabled, string $defaultLanguage): void
    {
        $connection->update('config_entry', [
            'value' => json_encode($enabled, JSON_THROW_ON_ERROR),
            'value_type' => 'boolean',
        ], ['config_key' => 'localization.route_prefixes_enabled']);
        $connection->update('config_entry', [
            'value' => json_encode($defaultLanguage, JSON_THROW_ON_ERROR),
            'value_type' => 'string',
        ], ['config_key' => 'localization.default_language']);
    }

    private function seedSystemErrorPage(Connection $connection, int $statusCode, string $title): void
    {
        $parentUid = $this->systemErrorUid($statusCode, 0);
        $contentUid = $this->systemErrorUid($statusCode, 1);
        $revisionUid = $this->systemErrorUid($statusCode, 2);
        $schemaUid = '10000000-0000-0000-0000-000000000001';
        $schemaVersionUid = '10000000-0000-0000-0000-000000000101';

        $connection->insert('content_item', [
            'uid' => $parentUid,
            'slug' => 'error-pages',
            'status' => 'published',
            'parent_uid' => 'system',
            'sort_order' => 0,
            'custom_url' => null,
            'redirect_target' => null,
            'schema_uid' => null,
            'schema_version' => null,
            'active_revision_uid' => null,
            'version' => 1,
            'available_languages' => json_encode(['en'], JSON_THROW_ON_ERROR),
            'available_variants' => json_encode(['default'], JSON_THROW_ON_ERROR),
            'visibility' => 'public',
            'acl_restrictions' => json_encode([], JSON_THROW_ON_ERROR),
            'view_min_level' => 0,
            'view_group_identifiers' => null,
            'edit_min_level' => 3,
            'edit_group_identifiers' => null,
            'manage_min_level' => 6,
            'manage_group_identifiers' => null,
            'metadata' => json_encode(['test' => true], JSON_THROW_ON_ERROR),
        ]);
        $connection->insert('content_item', [
            'uid' => $contentUid,
            'slug' => (string) $statusCode,
            'status' => 'published',
            'parent_uid' => $parentUid,
            'sort_order' => 0,
            'custom_url' => null,
            'redirect_target' => null,
            'schema_uid' => $schemaUid,
            'schema_version' => 1,
            'active_revision_uid' => null,
            'version' => 1,
            'available_languages' => json_encode(['en'], JSON_THROW_ON_ERROR),
            'available_variants' => json_encode(['default'], JSON_THROW_ON_ERROR),
            'visibility' => 'public',
            'acl_restrictions' => json_encode([], JSON_THROW_ON_ERROR),
            'view_min_level' => 0,
            'view_group_identifiers' => null,
            'edit_min_level' => 3,
            'edit_group_identifiers' => null,
            'manage_min_level' => 6,
            'manage_group_identifiers' => null,
            'metadata' => json_encode(['test' => true], JSON_THROW_ON_ERROR),
        ]);
        $connection->insert('content_revision', [
            'uid' => $revisionUid,
            'content_uid' => $contentUid,
            'version' => 1,
            'schema_uid' => $schemaUid,
            'schema_version_uid' => $schemaVersionUid,
            'change_summary' => 'Seeded custom error page.',
            'metadata' => json_encode(['test' => true], JSON_THROW_ON_ERROR),
        ]);

        $fieldIndex = 3;

        foreach ([
            'title' => $title,
            'subtitle' => 'Custom system error page.',
            'body' => ['html' => '<p>Rendered from a system content entity.</p>'],
        ] as $fieldIdentifier => $fieldContent) {
            $connection->insert('content_field_value', [
                'uid' => $this->systemErrorUid($statusCode, $fieldIndex),
                'revision_uid' => $revisionUid,
                'language' => 'en',
                'variant' => 'default',
                'field_identifier' => $fieldIdentifier,
                'field_content' => json_encode($fieldContent, JSON_THROW_ON_ERROR),
            ]);
            ++$fieldIndex;
        }

        $connection->update('content_item', ['active_revision_uid' => $revisionUid], ['uid' => $contentUid]);
    }

    private function removeSystemErrorPage(Connection $connection, int $statusCode): void
    {
        $parentUid = $this->systemErrorUid($statusCode, 0);
        $contentUid = $this->systemErrorUid($statusCode, 1);

        $connection->update('content_item', ['active_revision_uid' => null], ['uid' => $contentUid]);
        $connection->delete('content_item', ['uid' => $contentUid]);
        $connection->delete('content_item', ['uid' => $parentUid]);
    }

    private function systemErrorUid(int $statusCode, int $suffix): string
    {
        return sprintf('90000000-0000-0000-0000-%012d', ($statusCode * 10) + $suffix);
    }
}
