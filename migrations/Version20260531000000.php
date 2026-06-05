<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Database\TablePrefix;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531000000 extends AbstractMigration
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
        $this->addIndex($messenger, ['queue_name', 'available_at', 'delivered_at', 'id'], 'idx_messenger_queue_available');

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
        $this->addIndex($packageSetting, ['package_name'], 'idx_package_setting_package');

        $schedulerTask = $schema->createTable('scheduler_task');
        $schedulerTask->addColumn('identifier', 'string', ['length' => 160]);
        $schedulerTask->addColumn('label_key', 'string', ['length' => 160]);
        $schedulerTask->addColumn('description_key', 'string', ['length' => 160]);
        $schedulerTask->addColumn('source', 'string', ['length' => 160]);
        $schedulerTask->addColumn('type', 'string', ['length' => 255]);
        $schedulerTask->addColumn('target', 'string', ['length' => 255]);
        $schedulerTask->addColumn('cron_expression', 'string', ['length' => 120]);
        $schedulerTask->addColumn('default_cron_expression', 'string', ['length' => 120]);
        $schedulerTask->addColumn('status', 'string', ['length' => 255]);
        $schedulerTask->addColumn('trusted', 'boolean');
        $schedulerTask->addColumn('next_due_at', 'datetime_immutable', ['notnull' => false]);
        $schedulerTask->addColumn('last_attempt_at', 'datetime_immutable', ['notnull' => false]);
        $schedulerTask->addColumn('last_success_at', 'datetime_immutable', ['notnull' => false]);
        $schedulerTask->addColumn('failure_count', 'integer');
        $schedulerTask->addColumn('metadata', 'json');
        $schedulerTask->addColumn('modified_at', 'datetime_immutable');
        $this->addPrimaryKey($schedulerTask, 'identifier');
        $this->addIndex($schedulerTask, ['status', 'next_due_at'], 'idx_scheduler_task_status_due');
        $this->addIndex($schedulerTask, ['source'], 'idx_scheduler_task_source');

        $schedulerTaskRun = $schema->createTable('scheduler_task_run');
        $schedulerTaskRun->addColumn('uid', 'string', ['length' => 36]);
        $schedulerTaskRun->addColumn('task_identifier', 'string', ['length' => 160]);
        $schedulerTaskRun->addColumn('status', 'string', ['length' => 255]);
        $schedulerTaskRun->addColumn('started_at', 'datetime_immutable');
        $schedulerTaskRun->addColumn('finished_at', 'datetime_immutable', ['notnull' => false]);
        $schedulerTaskRun->addColumn('duration_ms', 'integer', ['notnull' => false]);
        $schedulerTaskRun->addColumn('context', 'json');
        $this->addPrimaryKey($schedulerTaskRun, 'uid');
        $this->addIndex($schedulerTaskRun, ['task_identifier', 'started_at'], 'idx_scheduler_task_run_task_started');
        $this->addIndex($schedulerTaskRun, ['status'], 'idx_scheduler_task_run_status');
        $schedulerTaskRun->addForeignKeyConstraint('scheduler_task', ['task_identifier'], ['identifier'], ['onDelete' => 'CASCADE'], $this->schemaObjectName('fk_scheduler_task_run_task'));

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
        $this->addUniqueIndex($stateMarker, ['subject_type', 'subject_uid', 'marker_key'], 'uniq_state_marker_subject_key');
        $this->addIndex($stateMarker, ['subject_type', 'subject_uid'], 'idx_state_marker_subject');
        $this->addIndex($stateMarker, ['subject_type', 'marker_key', 'marker_at'], 'idx_state_marker_lookup');
        $this->addIndex($stateMarker, ['marker_key', 'marker_at'], 'idx_state_marker_key_at');
        $this->addIndex($stateMarker, ['subject_type', 'marker_by'], 'idx_state_marker_by');

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
        $accessStatistic->addColumn('do_not_track', 'boolean');
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
        $this->addIndex($accessStatistic, ['request_id'], 'idx_access_statistic_request_id');
        $this->addIndex($accessStatistic, ['occurred_at'], 'idx_access_statistic_occurred_at');
        $this->addIndex($accessStatistic, ['visitor_id', 'occurred_at'], 'idx_access_statistic_visitor_at');
        $this->addIndex($accessStatistic, ['route', 'occurred_at'], 'idx_access_statistic_route_at');
        $this->addIndex($accessStatistic, ['resolved_route', 'occurred_at'], 'idx_access_statistic_resolved_at');
        $this->addIndex($accessStatistic, ['surface', 'occurred_at'], 'idx_access_statistic_surface_at');
        $this->addIndex($accessStatistic, ['http_status', 'occurred_at'], 'idx_access_statistic_status_at');
        $this->addIndex($accessStatistic, ['method', 'occurred_at'], 'idx_access_statistic_method_at');
        $this->addIndex($accessStatistic, ['browser_family', 'occurred_at'], 'idx_access_statistic_browser_at');
        $this->addIndex($accessStatistic, ['device_type', 'occurred_at'], 'idx_access_statistic_device_at');
        $this->addIndex($accessStatistic, ['is_bot', 'occurred_at'], 'idx_access_statistic_bot_at');
        $this->addIndex($accessStatistic, ['do_not_track', 'occurred_at'], 'idx_access_statistic_dnt_at');
        $this->addIndex($accessStatistic, ['referrer_host', 'occurred_at'], 'idx_access_statistic_referrer_at');
        $this->addIndex($accessStatistic, ['preferred_language', 'occurred_at'], 'idx_access_statistic_language_at');
        $this->addIndex($accessStatistic, ['country', 'occurred_at'], 'idx_access_statistic_country_at');
        $this->addIndex($accessStatistic, ['continent', 'occurred_at'], 'idx_access_statistic_continent_at');

        $aclGroup = $schema->createTable('acl_group');
        $aclGroup->addColumn('uid', 'string', ['length' => 36]);
        $aclGroup->addColumn('identifier', 'string', ['length' => 80]);
        $aclGroup->addColumn('name', 'string', ['length' => 160]);
        $aclGroup->addColumn('min_role', 'integer');
        $aclGroup->addColumn('metadata', 'json');
        $this->addPrimaryKey($aclGroup, 'uid');
        $this->addUniqueIndex($aclGroup, ['identifier'], 'uniq_acl_group_identifier');
        $this->addIndex($aclGroup, ['min_role'], 'idx_acl_group_min_role');

        $user = $schema->createTable('user_account');
        $user->addColumn('uid', 'string', ['length' => 36]);
        $user->addColumn('username', 'string', ['length' => 80]);
        $user->addColumn('email', 'string', ['length' => 180]);
        $user->addColumn('password_hash', 'string', ['length' => 255]);
        $user->addColumn('profile', 'json');
        $user->addColumn('settings', 'json');
        $user->addColumn('status', 'string', ['length' => 255]);
        $user->addColumn('role', 'string', ['length' => 40, 'default' => 'user']);
        $this->addPrimaryKey($user, 'uid');
        $this->addUniqueIndex($user, ['username'], 'uniq_user_account_username');
        $this->addUniqueIndex($user, ['email'], 'uniq_user_account_email');
        $this->addIndex($user, ['status'], 'idx_user_account_status');

        $userGroup = $schema->createTable('user_acl_group');
        $userGroup->addColumn('user_uid', 'string', ['length' => 36]);
        $userGroup->addColumn('group_uid', 'string', ['length' => 36]);
        $this->addPrimaryKey($userGroup, 'user_uid', 'group_uid');
        $this->addIndex($userGroup, ['user_uid'], 'idx_user_acl_group_user');
        $this->addIndex($userGroup, ['group_uid'], 'idx_user_acl_group_group');
        $userGroup->addForeignKeyConstraint('user_account', ['user_uid'], ['uid'], ['onDelete' => 'CASCADE'], $this->schemaObjectName('fk_user_acl_group_user'));
        $userGroup->addForeignKeyConstraint('acl_group', ['group_uid'], ['uid'], ['onDelete' => 'CASCADE'], $this->schemaObjectName('fk_user_acl_group_group'));

        $accountToken = $schema->createTable('account_token');
        $accountToken->addColumn('uid', 'string', ['length' => 36]);
        $accountToken->addColumn('token_hash', 'string', ['length' => 64]);
        $accountToken->addColumn('type', 'string', ['length' => 255]);
        $accountToken->addColumn('status', 'string', ['length' => 255]);
        $accountToken->addColumn('email', 'string', ['length' => 180]);
        $accountToken->addColumn('user_uid', 'string', ['length' => 36, 'notnull' => false]);
        $accountToken->addColumn('group_identifiers', 'json');
        $accountToken->addColumn('role', 'string', ['length' => 40, 'default' => 'user']);
        $accountToken->addColumn('metadata', 'json');
        $accountToken->addColumn('created_at', 'datetime_immutable');
        $accountToken->addColumn('expires_at', 'datetime_immutable');
        $accountToken->addColumn('consumed_at', 'datetime_immutable', ['notnull' => false]);
        $this->addPrimaryKey($accountToken, 'uid');
        $this->addUniqueIndex($accountToken, ['token_hash'], 'uniq_account_token_hash');
        $this->addIndex($accountToken, ['email'], 'idx_account_token_email');
        $this->addIndex($accountToken, ['type', 'status'], 'idx_account_token_type_status');
        $this->addIndex($accountToken, ['user_uid', 'type'], 'idx_account_token_user_type');
        $this->addIndex($accountToken, ['expires_at'], 'idx_account_token_expires_at');
        $accountToken->addForeignKeyConstraint('user_account', ['user_uid'], ['uid'], ['onDelete' => 'CASCADE'], $this->schemaObjectName('fk_account_token_user'));

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
        $this->addUniqueIndex($apiKey, ['hmac_hash'], 'uniq_api_key_hmac_hash');
        $this->addIndex($apiKey, ['prefix'], 'idx_api_key_prefix');
        $this->addIndex($apiKey, ['user_uid', 'status'], 'idx_api_key_user_status');
        $apiKey->addForeignKeyConstraint('user_account', ['user_uid'], ['uid'], ['onDelete' => 'CASCADE'], $this->schemaObjectName('fk_api_key_user'));

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
        $this->addUniqueIndex($extension, ['package_name'], 'uniq_extension_package_name');
        $this->addIndex($extension, ['status'], 'idx_extension_package_status');

        $menu = $schema->createTable('site_menu');
        $menu->addColumn('uid', 'string', ['length' => 36]);
        $menu->addColumn('identifier', 'string', ['length' => 80]);
        $menu->addColumn('labels', 'json');
        $menu->addColumn('active', 'boolean');
        $menu->addColumn('metadata', 'json');
        $this->addPrimaryKey($menu, 'uid');
        $this->addUniqueIndex($menu, ['identifier'], 'uniq_site_menu_identifier');

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
        $this->addIndex($menuItem, ['menu_uid', 'parent_uid', 'sort_order'], 'idx_site_menu_item_menu_parent_sort');
        $this->addIndex($menuItem, ['target_type', 'target_value'], 'idx_site_menu_item_target');
        $menuItem->addForeignKeyConstraint('site_menu', ['menu_uid'], ['uid'], ['onDelete' => 'CASCADE'], $this->schemaObjectName('fk_site_menu_item_menu'));

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
        $this->addUniqueIndex($contentSchema, ['identifier'], 'uniq_content_schema_identifier');
        $this->addIndex($contentSchema, ['source'], 'idx_content_schema_source');
        $this->addIndex($contentSchema, ['active_version_uid'], 'idx_content_schema_active_version');

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
        $this->addUniqueIndex($schemaVersion, ['schema_uid', 'version'], 'uniq_content_schema_version');
        $this->addIndex($schemaVersion, ['schema_uid'], 'idx_content_schema_version_schema');
        $this->addIndex($schemaVersion, ['definition_hash'], 'idx_content_schema_version_hash');
        $schemaVersion->addForeignKeyConstraint('content_schema', ['schema_uid'], ['uid'], ['onDelete' => 'CASCADE'], $this->schemaObjectName('fk_content_schema_version_schema'));

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
        $this->addIndex($content, ['slug'], 'idx_content_item_slug');
        $this->addIndex($content, ['parent_uid'], 'idx_content_item_parent');
        $this->addIndex($content, ['parent_uid', 'sort_order'], 'idx_content_item_parent_sort');
        $this->addIndex($content, ['status', 'visibility'], 'idx_content_item_status_visibility');
        $this->addIndex($content, ['schema_uid'], 'idx_content_item_schema');
        $this->addIndex($content, ['schema_uid', 'schema_version'], 'idx_content_item_schema_version');
        $this->addIndex($content, ['schema_uid', 'status', 'visibility'], 'idx_content_item_schema_status_visibility');
        $this->addIndex($content, ['active_revision_uid'], 'idx_content_item_active_revision');
        $this->addUniqueIndex($content, ['parent_uid', 'slug'], 'uniq_content_item_parent_slug');
        $this->addUniqueIndex($content, ['custom_url'], 'uniq_content_item_custom_url');
        $content->addForeignKeyConstraint('content_schema', ['schema_uid'], ['uid'], ['onDelete' => 'RESTRICT'], $this->schemaObjectName('fk_content_item_schema'));

        $revision = $schema->createTable('content_revision');
        $revision->addColumn('uid', 'string', ['length' => 36]);
        $revision->addColumn('content_uid', 'string', ['length' => 36]);
        $revision->addColumn('version', 'integer');
        $revision->addColumn('schema_uid', 'string', ['length' => 36]);
        $revision->addColumn('schema_version_uid', 'string', ['length' => 36]);
        $revision->addColumn('change_summary', 'string', ['length' => 255, 'notnull' => false]);
        $revision->addColumn('metadata', 'json');
        $this->addPrimaryKey($revision, 'uid');
        $this->addUniqueIndex($revision, ['content_uid', 'version'], 'uniq_content_revision_version');
        $this->addIndex($revision, ['content_uid'], 'idx_content_revision_content');
        $this->addIndex($revision, ['schema_uid'], 'idx_content_revision_schema');
        $this->addIndex($revision, ['schema_version_uid'], 'idx_content_revision_schema_version');
        $revision->addForeignKeyConstraint('content_item', ['content_uid'], ['uid'], ['onDelete' => 'CASCADE'], $this->schemaObjectName('fk_content_revision_content'));
        $revision->addForeignKeyConstraint('content_schema', ['schema_uid'], ['uid'], ['onDelete' => 'RESTRICT'], $this->schemaObjectName('fk_content_revision_schema'));
        $revision->addForeignKeyConstraint('content_schema_version', ['schema_version_uid'], ['uid'], ['onDelete' => 'RESTRICT'], $this->schemaObjectName('fk_content_revision_schema_version'));

        $fieldValue = $schema->createTable('content_field_value');
        $fieldValue->addColumn('uid', 'string', ['length' => 36]);
        $fieldValue->addColumn('revision_uid', 'string', ['length' => 36]);
        $fieldValue->addColumn('language', 'string', ['length' => 16]);
        $fieldValue->addColumn('variant', 'string', ['length' => 80]);
        $fieldValue->addColumn('field_identifier', 'string', ['length' => 160]);
        $fieldValue->addColumn('field_content', 'json');
        $this->addPrimaryKey($fieldValue, 'uid');
        $this->addIndex($fieldValue, ['revision_uid', 'language', 'variant'], 'idx_content_field_lookup');
        $this->addIndex($fieldValue, ['field_identifier'], 'idx_content_field_identifier');
        $this->addIndex($fieldValue, ['field_identifier', 'language', 'variant'], 'idx_content_field_identifier_variant');
        $this->addUniqueIndex($fieldValue, ['revision_uid', 'language', 'variant', 'field_identifier'], 'uniq_content_field_context_identifier');
        $fieldValue->addForeignKeyConstraint('content_revision', ['revision_uid'], ['uid'], ['onDelete' => 'CASCADE'], $this->schemaObjectName('fk_content_field_value_revision'));

        $contentSchema->addForeignKeyConstraint('content_schema_version', ['active_version_uid'], ['uid'], ['onDelete' => 'SET NULL'], $this->schemaObjectName('fk_content_schema_active_version'));
        $content->addForeignKeyConstraint('content_revision', ['active_revision_uid'], ['uid'], ['onDelete' => 'SET NULL'], $this->schemaObjectName('fk_content_item_active_revision'));
    }

    public function down(Schema $schema): void
    {
        $schema->getTable($this->tableName('content_item'))->removeForeignKey($this->schemaObjectName('fk_content_item_active_revision'));
        $schema->getTable($this->tableName('content_schema'))->removeForeignKey($this->schemaObjectName('fk_content_schema_active_version'));

        foreach ([
            'content_field_value',
            'content_revision',
            'content_item',
            'content_schema_version',
            'content_schema',
            'site_menu_item',
            'site_menu',
            'extension_package',
            'api_key',
            'account_token',
            'user_acl_group',
            'user_account',
            'acl_group',
            'scheduler_task_run',
            'scheduler_task',
            'package_setting_entry',
            'config_entry',
            'access_statistic_event',
            'state_marker',
            'messenger_messages',
        ] as $table) {
            $schema->dropTable($this->tableName($table));
        }
    }

    private function addPrimaryKey(Table $table, string $firstColumn, string ...$otherColumns): void
    {
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->setUnquotedName($this->schemaObjectName('pk_'.$table->getName()))
                ->setUnquotedColumnNames($firstColumn, ...$otherColumns)
                ->create(),
        );
    }

    /**
     * @param list<string> $columns
     */
    private function addIndex(Table $table, array $columns, string $name): void
    {
        $table->addIndex($columns, $this->schemaObjectName($name));
    }

    /**
     * @param list<string> $columns
     */
    private function addUniqueIndex(Table $table, array $columns, string $name): void
    {
        $table->addUniqueIndex($columns, $this->schemaObjectName($name));
    }

    private function schemaObjectName(string $name): string
    {
        $prefix = TablePrefix::fromEnvironment();

        if ('' === $prefix || str_starts_with($name, $prefix)) {
            return $name;
        }

        $prefixed = $prefix.$name;

        if (strlen($prefixed) <= 63) {
            return $prefixed;
        }

        return substr($prefixed, 0, 54).'_'.substr(sha1($prefixed), 0, 8);
    }

    private function tableName(string $name): string
    {
        return TablePrefix::apply($name);
    }
}
