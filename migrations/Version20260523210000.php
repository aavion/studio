<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the initial core configuration, ACL, package, menu, content schema, revision, and field value tables.';
    }

    public function up(Schema $schema): void
    {
        $messenger = $schema->createTable('messenger_messages');
        $messenger->addColumn('id', 'bigint', ['autoincrement' => true]);
        $messenger->addColumn('body', 'text');
        $messenger->addColumn('headers', 'text');
        $messenger->addColumn('queue_name', 'string', ['length' => 190]);
        $messenger->addColumn('created_at', 'datetime_immutable');
        $messenger->addColumn('available_at', 'datetime_immutable');
        $messenger->addColumn('delivered_at', 'datetime_immutable', ['notnull' => false]);
        $this->addPrimaryKey($messenger, 'id');
        $messenger->addIndex(['queue_name', 'available_at', 'delivered_at', 'id']);

        $config = $schema->createTable('config_entry');
        $config->addColumn('config_key', 'string', ['length' => 160]);
        $config->addColumn('value', 'json');
        $config->addColumn('value_type', 'string', ['length' => 255]);
        $config->addColumn('sensitive', 'boolean');
        $config->addColumn('modified_at', 'datetime_immutable');
        $config->addColumn('modified_by', 'string', ['length' => 180, 'notnull' => false]);
        $this->addPrimaryKey($config, 'config_key');

        $packageSetting = $schema->createTable('package_setting_entry');
        $packageSetting->addColumn('package_name', 'string', ['length' => 120]);
        $packageSetting->addColumn('setting_key', 'string', ['length' => 160]);
        $packageSetting->addColumn('value', 'json');
        $packageSetting->addColumn('value_type', 'string', ['length' => 255]);
        $packageSetting->addColumn('metadata', 'json');
        $packageSetting->addColumn('modified_at', 'datetime_immutable');
        $packageSetting->addColumn('modified_by', 'string', ['length' => 180, 'notnull' => false]);
        $this->addPrimaryKey($packageSetting, 'package_name', 'setting_key');
        $packageSetting->addIndex(['package_name'], 'idx_package_setting_package');

        $stateMarker = $schema->createTable('state_marker');
        $stateMarker->addColumn('uid', 'string', ['length' => 36]);
        $stateMarker->addColumn('subject_type', 'string', ['length' => 80]);
        $stateMarker->addColumn('subject_uid', 'string', ['length' => 36]);
        $stateMarker->addColumn('marker_key', 'string', ['length' => 80]);
        $stateMarker->addColumn('marker_at', 'datetime_immutable');
        $stateMarker->addColumn('marker_by', 'string', ['length' => 180, 'notnull' => false]);
        $stateMarker->addColumn('marker_value', 'string', ['length' => 255, 'notnull' => false]);
        $stateMarker->addColumn('metadata', 'json');
        $this->addPrimaryKey($stateMarker, 'uid');
        $stateMarker->addUniqueIndex(['subject_type', 'subject_uid', 'marker_key'], 'uniq_state_marker_subject_key');
        $stateMarker->addIndex(['subject_type', 'subject_uid'], 'idx_state_marker_subject');
        $stateMarker->addIndex(['subject_type', 'marker_key', 'marker_at'], 'idx_state_marker_lookup');
        $stateMarker->addIndex(['marker_key', 'marker_at'], 'idx_state_marker_key_at');
        $stateMarker->addIndex(['subject_type', 'marker_by'], 'idx_state_marker_by');

        $accessStatistic = $schema->createTable('access_statistic_event');
        $accessStatistic->addColumn('uid', 'string', ['length' => 36]);
        $accessStatistic->addColumn('occurred_at', 'datetime_immutable');
        $accessStatistic->addColumn('request_id', 'string', ['length' => 64]);
        $accessStatistic->addColumn('visitor_id', 'string', ['length' => 64]);
        $accessStatistic->addColumn('method', 'string', ['length' => 16]);
        $accessStatistic->addColumn('path', 'string', ['length' => 1024]);
        $accessStatistic->addColumn('requested_path', 'string', ['length' => 1024]);
        $accessStatistic->addColumn('route', 'string', ['length' => 190]);
        $accessStatistic->addColumn('resolved_route', 'string', ['length' => 190]);
        $accessStatistic->addColumn('surface', 'string', ['length' => 40]);
        $accessStatistic->addColumn('http_status', 'integer');
        $accessStatistic->addColumn('duration_ms', 'integer', ['notnull' => false]);
        $accessStatistic->addColumn('browser_family', 'string', ['length' => 40]);
        $accessStatistic->addColumn('device_type', 'string', ['length' => 40]);
        $accessStatistic->addColumn('is_bot', 'boolean');
        $accessStatistic->addColumn('referrer_host', 'string', ['length' => 255]);
        $accessStatistic->addColumn('preferred_language', 'string', ['length' => 20]);
        $accessStatistic->addColumn('request_content_type', 'string', ['length' => 120]);
        $accessStatistic->addColumn('response_content_type', 'string', ['length' => 120]);
        $accessStatistic->addColumn('response_size', 'integer', ['notnull' => false]);
        $accessStatistic->addColumn('city', 'string', ['length' => 80]);
        $accessStatistic->addColumn('state', 'string', ['length' => 80]);
        $accessStatistic->addColumn('country', 'string', ['length' => 80]);
        $accessStatistic->addColumn('continent', 'string', ['length' => 80]);
        $accessStatistic->addColumn('metadata', 'json');
        $this->addPrimaryKey($accessStatistic, 'uid');
        $accessStatistic->addIndex(['request_id'], 'idx_access_statistic_request_id');
        $accessStatistic->addIndex(['occurred_at'], 'idx_access_statistic_occurred_at');
        $accessStatistic->addIndex(['visitor_id', 'occurred_at'], 'idx_access_statistic_visitor_at');
        $accessStatistic->addIndex(['route', 'occurred_at'], 'idx_access_statistic_route_at');
        $accessStatistic->addIndex(['resolved_route', 'occurred_at'], 'idx_access_statistic_resolved_at');
        $accessStatistic->addIndex(['surface', 'occurred_at'], 'idx_access_statistic_surface_at');
        $accessStatistic->addIndex(['http_status', 'occurred_at'], 'idx_access_statistic_status_at');
        $accessStatistic->addIndex(['browser_family', 'occurred_at'], 'idx_access_statistic_browser_at');
        $accessStatistic->addIndex(['device_type', 'occurred_at'], 'idx_access_statistic_device_at');
        $accessStatistic->addIndex(['is_bot', 'occurred_at'], 'idx_access_statistic_bot_at');
        $accessStatistic->addIndex(['referrer_host', 'occurred_at'], 'idx_access_statistic_referrer_at');
        $accessStatistic->addIndex(['preferred_language', 'occurred_at'], 'idx_access_statistic_language_at');

        $aclGroup = $schema->createTable('acl_group');
        $aclGroup->addColumn('uid', 'string', ['length' => 36]);
        $aclGroup->addColumn('identifier', 'string', ['length' => 80]);
        $aclGroup->addColumn('name', 'json');
        $aclGroup->addColumn('access_level', 'integer');
        $aclGroup->addColumn('locked', 'boolean');
        $aclGroup->addColumn('allow_empty', 'boolean');
        $aclGroup->addColumn('metadata', 'json');
        $this->addPrimaryKey($aclGroup, 'uid');
        $aclGroup->addUniqueIndex(['identifier'], 'uniq_acl_group_identifier');
        $aclGroup->addIndex(['access_level'], 'idx_acl_group_access_level');

        $user = $schema->createTable('user_account');
        $user->addColumn('uid', 'string', ['length' => 36]);
        $user->addColumn('username', 'string', ['length' => 80]);
        $user->addColumn('email', 'string', ['length' => 180]);
        $user->addColumn('password_hash', 'string', ['length' => 255]);
        $user->addColumn('profile', 'json');
        $user->addColumn('settings', 'json');
        $user->addColumn('status', 'string', ['length' => 255]);
        $this->addPrimaryKey($user, 'uid');
        $user->addUniqueIndex(['username'], 'uniq_user_account_username');
        $user->addUniqueIndex(['email'], 'uniq_user_account_email');
        $user->addIndex(['status'], 'idx_user_account_status');

        $userGroup = $schema->createTable('user_acl_group');
        $userGroup->addColumn('user_uid', 'string', ['length' => 36]);
        $userGroup->addColumn('group_uid', 'string', ['length' => 36]);
        $this->addPrimaryKey($userGroup, 'user_uid', 'group_uid');
        $userGroup->addIndex(['user_uid'], 'IDX_E9B9849EB88D678D');
        $userGroup->addIndex(['group_uid'], 'IDX_E9B9849ED009EE7F');
        $userGroup->addForeignKeyConstraint('user_account', ['user_uid'], ['uid'], ['onDelete' => 'CASCADE'], 'fk_user_acl_group_user');
        $userGroup->addForeignKeyConstraint('acl_group', ['group_uid'], ['uid'], ['onDelete' => 'CASCADE'], 'fk_user_acl_group_group');

        $apiKey = $schema->createTable('api_key');
        $apiKey->addColumn('uid', 'string', ['length' => 36]);
        $apiKey->addColumn('prefix', 'string', ['length' => 16]);
        $apiKey->addColumn('hmac_hash', 'string', ['length' => 64]);
        $apiKey->addColumn('encrypted_key', 'text');
        $apiKey->addColumn('user_uid', 'string', ['length' => 36]);
        $apiKey->addColumn('status', 'string', ['length' => 255]);
        $apiKey->addColumn('created_at', 'datetime_immutable');
        $apiKey->addColumn('revoked_at', 'datetime_immutable', ['notnull' => false]);
        $this->addPrimaryKey($apiKey, 'uid');
        $apiKey->addUniqueIndex(['hmac_hash'], 'uniq_api_key_hmac_hash');
        $apiKey->addIndex(['prefix'], 'idx_api_key_prefix');
        $apiKey->addIndex(['user_uid', 'status'], 'idx_api_key_user_status');
        $apiKey->addForeignKeyConstraint('user_account', ['user_uid'], ['uid'], ['onDelete' => 'CASCADE'], 'fk_api_key_user');

        $extension = $schema->createTable('extension_package');
        $extension->addColumn('uid', 'string', ['length' => 36]);
        $extension->addColumn('package_scopes', 'json');
        $extension->addColumn('package_name', 'string', ['length' => 120]);
        $extension->addColumn('path', 'string', ['length' => 512]);
        $extension->addColumn('manifest_version', 'string', ['length' => 40, 'notnull' => false]);
        $extension->addColumn('installed_version', 'string', ['length' => 40, 'notnull' => false]);
        $extension->addColumn('available_version', 'string', ['length' => 40, 'notnull' => false]);
        $extension->addColumn('status', 'string', ['length' => 255]);
        $extension->addColumn('metadata', 'json');
        $extension->addColumn('modified_at', 'datetime_immutable');
        $this->addPrimaryKey($extension, 'uid');
        $extension->addUniqueIndex(['package_name'], 'uniq_extension_package_name');
        $extension->addIndex(['status'], 'idx_extension_package_status');

        $menu = $schema->createTable('site_menu');
        $menu->addColumn('uid', 'string', ['length' => 36]);
        $menu->addColumn('identifier', 'string', ['length' => 80]);
        $menu->addColumn('labels', 'json');
        $menu->addColumn('active', 'boolean');
        $menu->addColumn('metadata', 'json');
        $this->addPrimaryKey($menu, 'uid');
        $menu->addUniqueIndex(['identifier'], 'uniq_site_menu_identifier');

        $menuItem = $schema->createTable('site_menu_item');
        $menuItem->addColumn('uid', 'string', ['length' => 36]);
        $menuItem->addColumn('menu_uid', 'string', ['length' => 36]);
        $menuItem->addColumn('parent_uid', 'string', ['length' => 36, 'notnull' => false]);
        $menuItem->addColumn('sort_order', 'integer');
        $menuItem->addColumn('labels', 'json');
        $menuItem->addColumn('target_type', 'string', ['length' => 40]);
        $menuItem->addColumn('target_value', 'string', ['length' => 512]);
        $menuItem->addColumn('view_min_level', 'integer', ['notnull' => false]);
        $menuItem->addColumn('view_group_identifiers', 'json', ['notnull' => false]);
        $menuItem->addColumn('metadata', 'json');
        $this->addPrimaryKey($menuItem, 'uid');
        $menuItem->addIndex(['menu_uid', 'parent_uid', 'sort_order'], 'idx_site_menu_item_menu_parent_sort');
        $menuItem->addIndex(['target_type', 'target_value'], 'idx_site_menu_item_target');
        $menuItem->addForeignKeyConstraint('site_menu', ['menu_uid'], ['uid'], ['onDelete' => 'CASCADE'], 'fk_site_menu_item_menu');

        $contentSchema = $schema->createTable('content_schema');
        $contentSchema->addColumn('uid', 'string', ['length' => 36]);
        $contentSchema->addColumn('identifier', 'string', ['length' => 120]);
        $contentSchema->addColumn('source', 'string', ['length' => 255]);
        $contentSchema->addColumn('locked', 'boolean');
        $contentSchema->addColumn('active_version_uid', 'string', ['length' => 36, 'notnull' => false]);
        $contentSchema->addColumn('labels', 'json');
        $contentSchema->addColumn('descriptions', 'json');
        $contentSchema->addColumn('metadata', 'json');
        $this->addPrimaryKey($contentSchema, 'uid');
        $contentSchema->addUniqueIndex(['identifier'], 'uniq_content_schema_identifier');
        $contentSchema->addIndex(['source'], 'idx_content_schema_source');
        $contentSchema->addIndex(['active_version_uid'], 'idx_content_schema_active_version');

        $schemaVersion = $schema->createTable('content_schema_version');
        $schemaVersion->addColumn('uid', 'string', ['length' => 36]);
        $schemaVersion->addColumn('schema_uid', 'string', ['length' => 36]);
        $schemaVersion->addColumn('version', 'integer');
        $schemaVersion->addColumn('title', 'json');
        $schemaVersion->addColumn('description', 'json');
        $schemaVersion->addColumn('definition', 'json');
        $schemaVersion->addColumn('custom_twig', 'text', ['notnull' => false]);
        $schemaVersion->addColumn('definition_hash', 'string', ['length' => 64]);
        $schemaVersion->addColumn('use_min_level', 'integer', ['notnull' => false]);
        $schemaVersion->addColumn('use_group_identifiers', 'json', ['notnull' => false]);
        $schemaVersion->addColumn('edit_min_level', 'integer', ['notnull' => false]);
        $schemaVersion->addColumn('edit_group_identifiers', 'json', ['notnull' => false]);
        $schemaVersion->addColumn('manage_min_level', 'integer', ['notnull' => false]);
        $schemaVersion->addColumn('manage_group_identifiers', 'json', ['notnull' => false]);
        $schemaVersion->addColumn('metadata', 'json');
        $this->addPrimaryKey($schemaVersion, 'uid');
        $schemaVersion->addUniqueIndex(['schema_uid', 'version'], 'uniq_content_schema_version');
        $schemaVersion->addIndex(['schema_uid'], 'idx_content_schema_version_schema');
        $schemaVersion->addIndex(['definition_hash'], 'idx_content_schema_version_hash');
        $schemaVersion->addForeignKeyConstraint('content_schema', ['schema_uid'], ['uid'], ['onDelete' => 'CASCADE'], 'fk_content_schema_version_schema');

        $content = $schema->createTable('content_item');
        $content->addColumn('uid', 'string', ['length' => 36]);
        $content->addColumn('slug', 'string', ['length' => 160]);
        $content->addColumn('status', 'string', ['length' => 255]);
        $content->addColumn('parent_uid', 'string', ['length' => 36, 'default' => '/']);
        $content->addColumn('sort_order', 'integer');
        $content->addColumn('custom_url', 'string', ['length' => 1024, 'notnull' => false]);
        $content->addColumn('redirect_target', 'string', ['length' => 1024, 'notnull' => false]);
        $content->addColumn('schema_uid', 'string', ['length' => 36, 'notnull' => false]);
        $content->addColumn('schema_version', 'integer', ['notnull' => false]);
        $content->addColumn('active_revision_uid', 'string', ['length' => 36, 'notnull' => false]);
        $content->addColumn('version', 'integer');
        $content->addColumn('available_languages', 'json');
        $content->addColumn('available_variants', 'json');
        $content->addColumn('visibility', 'string', ['length' => 255]);
        $content->addColumn('acl_restrictions', 'json');
        $content->addColumn('view_min_level', 'integer', ['notnull' => false]);
        $content->addColumn('view_group_identifiers', 'json', ['notnull' => false]);
        $content->addColumn('edit_min_level', 'integer', ['notnull' => false]);
        $content->addColumn('edit_group_identifiers', 'json', ['notnull' => false]);
        $content->addColumn('manage_min_level', 'integer', ['notnull' => false]);
        $content->addColumn('manage_group_identifiers', 'json', ['notnull' => false]);
        $content->addColumn('metadata', 'json');
        $this->addPrimaryKey($content, 'uid');
        $content->addIndex(['slug'], 'idx_content_item_slug');
        $content->addIndex(['parent_uid'], 'idx_content_item_parent');
        $content->addIndex(['parent_uid', 'sort_order'], 'idx_content_item_parent_sort');
        $content->addIndex(['status', 'visibility'], 'idx_content_item_status_visibility');
        $content->addIndex(['schema_uid'], 'idx_content_item_schema');
        $content->addIndex(['schema_uid', 'schema_version'], 'idx_content_item_schema_version');
        $content->addIndex(['schema_uid', 'status', 'visibility'], 'idx_content_item_schema_status_visibility');
        $content->addIndex(['active_revision_uid'], 'idx_content_item_active_revision');
        $content->addUniqueIndex(['parent_uid', 'slug'], 'uniq_content_item_parent_slug');
        $content->addUniqueIndex(['custom_url'], 'uniq_content_item_custom_url');
        $content->addForeignKeyConstraint('content_schema', ['schema_uid'], ['uid'], ['onDelete' => 'RESTRICT'], 'fk_content_item_schema');

        $revision = $schema->createTable('content_revision');
        $revision->addColumn('uid', 'string', ['length' => 36]);
        $revision->addColumn('content_uid', 'string', ['length' => 36]);
        $revision->addColumn('version', 'integer');
        $revision->addColumn('schema_uid', 'string', ['length' => 36]);
        $revision->addColumn('schema_version_uid', 'string', ['length' => 36]);
        $revision->addColumn('change_summary', 'string', ['length' => 255, 'notnull' => false]);
        $revision->addColumn('metadata', 'json');
        $this->addPrimaryKey($revision, 'uid');
        $revision->addUniqueIndex(['content_uid', 'version'], 'uniq_content_revision_version');
        $revision->addIndex(['content_uid'], 'idx_content_revision_content');
        $revision->addIndex(['schema_uid'], 'idx_content_revision_schema');
        $revision->addIndex(['schema_version_uid'], 'idx_content_revision_schema_version');
        $revision->addForeignKeyConstraint('content_item', ['content_uid'], ['uid'], ['onDelete' => 'CASCADE'], 'fk_content_revision_content');
        $revision->addForeignKeyConstraint('content_schema', ['schema_uid'], ['uid'], ['onDelete' => 'RESTRICT'], 'fk_content_revision_schema');
        $revision->addForeignKeyConstraint('content_schema_version', ['schema_version_uid'], ['uid'], ['onDelete' => 'RESTRICT'], 'fk_content_revision_schema_version');

        $fieldValue = $schema->createTable('content_field_value');
        $fieldValue->addColumn('uid', 'string', ['length' => 36]);
        $fieldValue->addColumn('revision_uid', 'string', ['length' => 36]);
        $fieldValue->addColumn('language', 'string', ['length' => 16]);
        $fieldValue->addColumn('variant', 'string', ['length' => 80]);
        $fieldValue->addColumn('field_identifier', 'string', ['length' => 160]);
        $fieldValue->addColumn('field_content', 'json');
        $this->addPrimaryKey($fieldValue, 'uid');
        $fieldValue->addIndex(['revision_uid', 'language', 'variant'], 'idx_content_field_lookup');
        $fieldValue->addIndex(['field_identifier'], 'idx_content_field_identifier');
        $fieldValue->addIndex(['field_identifier', 'language', 'variant'], 'idx_content_field_identifier_variant');
        $fieldValue->addUniqueIndex(['revision_uid', 'language', 'variant', 'field_identifier'], 'uniq_content_field_context_identifier');
        $fieldValue->addForeignKeyConstraint('content_revision', ['revision_uid'], ['uid'], ['onDelete' => 'CASCADE'], 'fk_content_field_value_revision');

        $contentSchema->addForeignKeyConstraint('content_schema_version', ['active_version_uid'], ['uid'], ['onDelete' => 'SET NULL'], 'fk_content_schema_active_version');
        $content->addForeignKeyConstraint('content_revision', ['active_revision_uid'], ['uid'], ['onDelete' => 'SET NULL'], 'fk_content_item_active_revision');
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('content_item')->removeForeignKey('fk_content_item_active_revision');
        $schema->getTable('content_schema')->removeForeignKey('fk_content_schema_active_version');

        $schema->dropTable('content_field_value');
        $schema->dropTable('content_revision');
        $schema->dropTable('content_item');
        $schema->dropTable('content_schema_version');
        $schema->dropTable('content_schema');
        $schema->dropTable('site_menu_item');
        $schema->dropTable('site_menu');
        $schema->dropTable('extension_package');
        $schema->dropTable('api_key');
        $schema->dropTable('user_acl_group');
        $schema->dropTable('user_account');
        $schema->dropTable('acl_group');
        $schema->dropTable('package_setting_entry');
        $schema->dropTable('config_entry');
        $schema->dropTable('access_statistic_event');
        $schema->dropTable('state_marker');
        $schema->dropTable('messenger_messages');
    }

    private function addPrimaryKey(Table $table, string $firstColumn, string ...$otherColumns): void
    {
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->setUnquotedColumnNames($firstColumn, ...$otherColumns)
                ->create(),
        );
    }
}
