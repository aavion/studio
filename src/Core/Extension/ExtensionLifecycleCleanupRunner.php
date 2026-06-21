<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\AdminAcl\AdminFeatureOverrideStore;
use App\Core\AdminAcl\AdminFeatureRegistry;
use App\Core\Config\ConfigMessageCode;
use App\Core\Config\ConfigMessageKey;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;

final readonly class ExtensionLifecycleCleanupRunner implements ExtensionLifecycleCleanupRunnerInterface
{
    public function __construct(
        private Settings\ExtensionSettings $extensionSettings,
        private AdminFeatureOverrideStore $adminFeatureOverrideStore,
        private ?AdminFeatureRegistry $adminFeatureRegistry = null,
        private ?Database\ExtensionDatabaseSchemaSynchronizer $databaseSynchronizer = null,
        private ?Content\ExtensionContentSchemaSynchronizer $contentSchemaSynchronizer = null,
    ) {
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function cleanup(Extension $extension): WorkflowResult
    {
        $actions = [];
        $messages = [];
        $settingsSnapshot = $this->extensionSettings->cleanupSnapshot($extension->extensionName());

        if (!$settingsSnapshot->isSuccess()) {
            return WorkflowResult::failed($settingsSnapshot->issues(), [
                'extension' => $extension->extensionName(),
                'actions' => $actions,
                'settings_snapshot_context' => $settingsSnapshot->context(),
            ], $settingsSnapshot->messages());
        }

        $aclSnapshot = $this->adminFeatureOverrideStore->overrides();
        $settingsAndAcl = $this->cleanupSettingsAndAcl($extension->extensionName());
        if (!$settingsAndAcl->isSuccess()) {
            $restoreMessages = $this->restoreSettingsAndAclSnapshot($extension->extensionName(), $settingsSnapshot->value(), $aclSnapshot);

            return WorkflowResult::failed($settingsAndAcl->issues(), [
                'extension' => $extension->extensionName(),
                'actions' => $actions,
                'settings_acl_context' => $settingsAndAcl->context(),
            ], [...$settingsAndAcl->messages(), ...$restoreMessages]);
        }

        $actions = [...$actions, ...$settingsAndAcl->value()];
        $messages = [...$messages, ...$settingsAndAcl->messages()];

        if (null !== $this->databaseSynchronizer) {
            $database = $this->databaseSynchronizer->purge($extension);
            if (!$database->isSuccess()) {
                $messages = [...$messages, ...$this->restoreSettingsAndAclSnapshot($extension->extensionName(), $settingsSnapshot->value(), $aclSnapshot)];

                return WorkflowResult::failed($database->issues(), [
                    'extension' => $extension->extensionName(),
                    'actions' => $actions,
                    'database_context' => $database->context(),
                ], [...$messages, ...$database->messages()]);
            }

            $messages = [...$messages, ...$database->messages()];
            $actions[] = [
                'action' => 'drop_extension_database_tables',
                'count' => count($database->value()['dropped'] ?? []),
            ];
        }

        if (null !== $this->contentSchemaSynchronizer) {
            $schemas = $this->contentSchemaSynchronizer->purge($extension);
            if (!$schemas->isSuccess()) {
                $messages = [...$messages, ...$this->restoreSettingsAndAclSnapshot($extension->extensionName(), $settingsSnapshot->value(), $aclSnapshot)];

                return WorkflowResult::failed($schemas->issues(), [
                    'extension' => $extension->extensionName(),
                    'actions' => $actions,
                    'content_schema_context' => $schemas->context(),
                ], [...$messages, ...$schemas->messages()]);
            }

            $messages = [...$messages, ...$schemas->messages()];
            $actions[] = [
                'action' => 'delete_extension_content_schemas',
                'count' => count($schemas->value()['deleted'] ?? []),
            ];
        }

        $this->adminFeatureRegistry?->resetCache();

        return WorkflowResult::success([
            'extension' => $extension->extensionName(),
            'actions' => $actions,
        ], [
            'extension' => $extension->extensionName(),
            'actions' => $actions,
        ], [
            ...$messages,
            Message::create(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_CLEANUP_COMPLETED,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_CLEANUP_COMPLETED,
                ['%extension%' => $extension->extensionName()],
                ['extension' => $extension->extensionName(), 'actions' => $actions],
                MessageLevel::Success,
            ),
        ]);
    }

    /**
     * @return WorkflowResult<list<array{action: string, count: int}>>
     */
    private function cleanupSettingsAndAcl(string $extensionName): WorkflowResult
    {
        $actions = [];

        $settingsCleanup = $this->extensionSettings->removeExtensionForCleanup($extensionName);
        if (!$settingsCleanup->isSuccess()) {
            return WorkflowResult::failed($settingsCleanup->issues(), [
                'extension' => $extensionName,
                'actions' => $actions,
                'settings_context' => $settingsCleanup->context(),
            ], $settingsCleanup->messages());
        }

        $deletedSettings = $settingsCleanup->value();

        if ($deletedSettings > 0) {
            $actions[] = [
                'action' => 'delete_extension_settings',
                'count' => $deletedSettings,
            ];
        }

        $removedAclOverride = $this->removeExtensionAclOverride($extensionName);

        if (false === $removedAclOverride) {
            return WorkflowResult::failed([
                Message::error(
                    ConfigMessageCode::CONFIG_WRITE_FAILED,
                    ConfigMessageKey::CONFIG_WRITE_FAILED,
                    ['%key%' => AdminFeatureOverrideStore::CONFIG_KEY],
                    [
                        'extension' => $extensionName,
                        'feature' => $this->extensionAdminFeature($extensionName),
                        'config_key' => AdminFeatureOverrideStore::CONFIG_KEY,
                        'operation' => 'extension_lifecycle_cleanup',
                    ],
                ),
            ], [
                'extension' => $extensionName,
                'actions' => $actions,
                'config_key' => AdminFeatureOverrideStore::CONFIG_KEY,
            ]);
        }

        if (true === $removedAclOverride) {
            $actions[] = [
                'action' => 'delete_extension_acl_override',
                'count' => 1,
            ];
        }

        return WorkflowResult::success($actions, [
            'extension' => $extensionName,
            'actions' => $actions,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $settingsRows
     * @param array<string, array<string, mixed>> $aclOverrides
     *
     * @return list<Message>
     */
    private function restoreSettingsAndAclSnapshot(string $extensionName, array $settingsRows, array $aclOverrides): array
    {
        $messages = [];
        $settings = $this->extensionSettings->restoreCleanupSnapshot($extensionName, $settingsRows);
        if (!$settings->isSuccess()) {
            $messages = [...$messages, ...$settings->issues(), ...$settings->messages()];
        }

        if (!$this->adminFeatureOverrideStore->save($aclOverrides, 'extension_lifecycle_cleanup_rollback')) {
            $messages[] = Message::error(
                ConfigMessageCode::CONFIG_WRITE_FAILED,
                ConfigMessageKey::CONFIG_WRITE_FAILED,
                ['%key%' => AdminFeatureOverrideStore::CONFIG_KEY],
                [
                    'extension' => $extensionName,
                    'config_key' => AdminFeatureOverrideStore::CONFIG_KEY,
                    'operation' => 'extension_lifecycle_cleanup_rollback',
                ],
            );
        }

        return $messages;
    }

    private function removeExtensionAclOverride(string $extensionName): ?bool
    {
        $feature = $this->extensionAdminFeature($extensionName);
        $overrides = $this->adminFeatureOverrideStore->overrides();

        if (!isset($overrides[$feature])) {
            return null;
        }

        unset($overrides[$feature]);

        return $this->adminFeatureOverrideStore->save($overrides, 'extension_lifecycle_cleanup');
    }

    private function extensionAdminFeature(string $extensionName): string
    {
        return 'admin.settings.extensions.'.$extensionName;
    }
}
