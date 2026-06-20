<?php

declare(strict_types=1);

namespace App\Core\Extension\Install;

use App\Content\ContentStatus;
use App\Core\Manifest\Manifest;
use App\Core\Message\Message;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\ExtensionActivator;
use App\Core\Extension\ExtensionDiscoveryRunner;
use App\Core\Extension\ExtensionManifestSpec;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Extension\ExtensionScope;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;
use Throwable;

final readonly class ExtensionInstallApplier
{
    public function __construct(
        private ExtensionDiscoveryRunner $discoveryRunner,
        private ExtensionActivator $activator,
        private ExtensionInstallFilesystem $filesystem,
        private ExtensionInstallPayload $payloadReader,
        private ExtensionInstallStageReader $stageReader,
        private ExtensionInstallRegistry $registry,
        private ExtensionInstallVersionGuard $versionGuard,
        private ExtensionReplacementPreflight $replacementPreflight,
        private ExtensionInstallRollbacker $rollbacker,
        private ExtensionReactivationPlanner $reactivationPlanner,
        private string $environment,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    public function apply(array $payload): WorkflowResult
    {
        $installId = $this->payloadReader->string($payload, 'install_id');
        $slug = $this->payloadReader->string($payload, 'extension');

        if (null === $installId || null === $slug || !ExtensionManifestSpec::isValidSlug($slug)) {
            return $this->payloadReader->invalid('apply', array_keys($payload));
        }

        $root = $this->filesystem->installRoot($this->environment, $installId);
        $stagePath = $root.DIRECTORY_SEPARATOR.'staged';
        $extensionRoot = $this->stageReader->extensionRoot($stagePath);

        if (null === $extensionRoot) {
            return WorkflowResult::invalid([
                Message::error(
                    ExtensionMessageCode::EXTENSION_INSTALL_ROOT_INVALID,
                    ExtensionMessageKey::EXTENSION_INSTALL_ROOT_INVALID,
                    context: ['install_id' => $installId, 'extension' => $slug],
                ),
            ]);
        }

        $symlink = $this->filesystem->firstSymlinkPath($extensionRoot);
        if (null !== $symlink) {
            return WorkflowResult::invalid([
                Message::warning(
                    ExtensionMessageCode::EXTENSION_INSTALL_ZIP_INVALID,
                    ExtensionMessageKey::EXTENSION_INSTALL_ZIP_INVALID,
                    context: [
                        'install_id' => $installId,
                        'reason' => 'symlink_entry',
                        'entry' => $this->filesystem->relativePath($symlink),
                    ],
                ),
            ]);
        }

        $messages = [];
        $existing = $this->registry->extension($slug);
        $wasActive = $existing instanceof Extension && ExtensionStatus::Active === $existing->status();
        $previousStatus = $existing?->status();
        $target = $this->filesystem->extensionTarget($slug);
        $prepared = $root.DIRECTORY_SEPARATOR.'prepared'.DIRECTORY_SEPARATOR.$slug;
        $backup = $root.DIRECTORY_SEPARATOR.'backup'.DIRECTORY_SEPARATOR.$slug;
        $deactivationTargets = [];
        $previousStatuses = $previousStatus instanceof ExtensionStatus ? [$slug => $previousStatus] : [];
        $contentStatusSnapshots = [];

        $manifest = $this->stageReader->readManifest($extensionRoot);
        if (!$manifest->isSuccess()) {
            return $manifest;
        }

        $manifestValue = $manifest->value();
        try {
            $scopes = ExtensionScope::fromManifestValue((string) $manifestValue->get('EXTENSION_SCOPE', ''));
        } catch (\InvalidArgumentException) {
            return WorkflowResult::invalid([
                Message::error(
                    ExtensionMessageCode::EXTENSION_SCOPE_INVALID,
                    ExtensionMessageKey::EXTENSION_SCOPE_INVALID,
                    ['%scope%' => (string) $manifestValue->get('EXTENSION_SCOPE', '')],
                    ['install_id' => $installId, 'extension' => $slug],
                ),
            ]);
        }

        $versionGate = $this->versionGuard->ensureAllowed($manifestValue, $existing, $installId, $slug);
        if ($versionGate instanceof WorkflowResult) {
            return $versionGate;
        }

        if ($wasActive) {
            $dependencyPreflight = $this->replacementPreflight->dependencies($manifestValue, $slug, $scopes);

            if (!$dependencyPreflight->isSuccess()) {
                return WorkflowResult::blocked($dependencyPreflight->issues(), [
                    'install_id' => $installId,
                    'extension' => $slug,
                    'dependencies' => $dependencyPreflight->context()['dependencies'] ?? [],
                ], $dependencyPreflight->messages());
            }

            $deactivationPlan = $this->activator->planDeactivation($slug);

            if (!$deactivationPlan->isSuccess()) {
                return WorkflowResult::blocked($deactivationPlan->issues(), [
                    'install_id' => $installId,
                    'extension' => $slug,
                    'deactivation_context' => $deactivationPlan->context(),
                ], $deactivationPlan->messages());
            }

            $deactivationTargets = $this->replacementPreflight->extensionNameList($deactivationPlan->value()['deactivate'] ?? []);
            $previousStatuses = $this->registry->statusSnapshots($deactivationTargets);
            $deactivation = $this->activator->deactivate($slug, $this->environment, rebuildAssets: false);
            $messages = [...$messages, ...$deactivation->messages()];

            if (!$deactivation->isSuccess()) {
                return WorkflowResult::failed($deactivation->issues(), [
                    'install_id' => $installId,
                    'extension' => $slug,
                    'deactivation_context' => $deactivation->context(),
                ], $messages);
            }

            $contentStatusSnapshots = $this->contentStatusSnapshots($deactivation->context()['content_status_snapshots'] ?? []);
        }

        try {
            $this->filesystem->prepareReplacement($extensionRoot, $prepared);
            $this->filesystem->swapPreparedExtension($prepared, $target, $backup);
        } catch (Throwable $error) {
            $rollbackMessages = [
                ...$this->registry->restoreStatuses($previousStatuses),
                ...$this->registry->restoreContentStatuses($contentStatusSnapshots),
            ];

            return WorkflowResult::failed([
                Message::exception(
                    OperationMessageCode::OPERATION_EXCEPTION,
                    OperationMessageKey::OPERATION_EXCEPTION,
                    context: [
                        'install_id' => $installId,
                        'extension' => $slug,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                ),
            ], [
                'install_id' => $installId,
                'extension' => $slug,
                'replacement_stage' => 'filesystem_swap',
                'rolled_back' => [] === $rollbackMessages,
            ], [...$messages, ...$rollbackMessages]);
        }

        $discovery = ($this->discoveryRunner)('extension_install');
        $messages = [...$messages, ...$discovery->messages()];

        if (!$discovery->isSuccess()) {
            $rollbackMessages = $this->rollbacker->previousExtension($slug, $target, $backup, $previousStatuses, $contentStatusSnapshots);

            return WorkflowResult::failed($discovery->issues(), [
                'install_id' => $installId,
                'extension' => $slug,
                'discovery_context' => $discovery->context(),
                'rolled_back' => [] === $rollbackMessages,
            ], [...$messages, ...$rollbackMessages]);
        }

        $installed = $this->registry->extension($slug);
        if ($installed instanceof Extension && ExtensionStatus::Active === $installed->status()) {
            $installed->restoreStatus(ExtensionStatus::Inactive);
            $this->registry->flush();
        }

        $installed = $this->registry->extension($slug);
        if (!$this->isInstalledInactiveExtension($installed, $manifestValue)) {
            $rollbackMessages = $this->rollbacker->previousExtension($slug, $target, $backup, $previousStatuses, $contentStatusSnapshots);
            $status = $installed?->status()->value;

            return WorkflowResult::failed([
                Message::error(
                    ExtensionMessageCode::EXTENSION_REGISTRY_EXTENSION_FAULTY,
                    ExtensionMessageKey::EXTENSION_REGISTRY_EXTENSION_FAULTY,
                    ['%extension%' => $slug],
                    [
                        'install_id' => $installId,
                        'extension' => $slug,
                        'status' => $status,
                        'expected_status' => ExtensionStatus::Inactive->value,
                    ],
                ),
            ], [
                'install_id' => $installId,
                'extension' => $slug,
                'status' => $status,
                'rolled_back' => [] === $rollbackMessages,
            ], [...$messages, ...$rollbackMessages]);
        }

        if ($wasActive) {
            $reactivationTargets = $this->reactivationPlanner->order($slug, $deactivationTargets, $previousStatuses);
            $activation = $this->activator->activate($slug, $this->environment, rebuildAssets: [] === $reactivationTargets);
            $messages = [...$messages, ...$activation->messages()];

            if (!$activation->isSuccess()) {
                $rollbackMessages = $this->rollbacker->previousExtension($slug, $target, $backup, $previousStatuses, $contentStatusSnapshots);

                return WorkflowResult::failed($activation->issues(), [
                    'install_id' => $installId,
                    'extension' => $slug,
                    'activation_context' => $activation->context(),
                    'rolled_back' => [] === $rollbackMessages,
                ], [...$messages, ...$rollbackMessages]);
            }

            $lastReactivationIndex = count($reactivationTargets) - 1;
            foreach ($reactivationTargets as $index => $extensionName) {
                $reactivation = $this->activator->activate($extensionName, $this->environment, rebuildAssets: $index === $lastReactivationIndex);
                $messages = [...$messages, ...$reactivation->messages()];

                if (!$reactivation->isSuccess()) {
                    $rollbackMessages = $this->rollbacker->previousExtension($slug, $target, $backup, $previousStatuses, $contentStatusSnapshots);

                    return WorkflowResult::failed($reactivation->issues(), [
                        'install_id' => $installId,
                        'extension' => $slug,
                        'reactivation_extension' => $extensionName,
                        'reactivation_context' => $reactivation->context(),
                        'rolled_back' => [] === $rollbackMessages,
                    ], [...$messages, ...$rollbackMessages]);
                }
            }

            $contentStatusMessages = $this->registry->restoreContentStatuses($contentStatusSnapshots);
            $messages = [...$messages, ...$contentStatusMessages];
        }

        $this->filesystem->removePath($root);

        return WorkflowResult::success([
            'extension' => $slug,
            'was_active' => $wasActive,
        ], [
            'extension' => $slug,
            'was_active' => $wasActive,
        ], [
            ...$messages,
            Message::success(
                ExtensionMessageKey::EXTENSION_INSTALL_COMPLETED,
                ['%extension%' => $slug],
                ['extension' => $slug, 'was_active' => $wasActive],
            ),
        ]);
    }

    private function isInstalledInactiveExtension(?Extension $extension, Manifest $manifest): bool
    {
        if (!$extension instanceof Extension || ExtensionStatus::Inactive !== $extension->status()) {
            return false;
        }

        $expectedVersion = trim((string) $manifest->get('EXTENSION_VERSION', ''));

        return '' === $expectedVersion || $extension->manifestVersion() === $expectedVersion;
    }

    /**
     * @return array<string, ContentStatus>
     */
    private function contentStatusSnapshots(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $snapshots = [];

        foreach ($value as $contentUid => $status) {
            if (!is_string($contentUid) || !is_string($status)) {
                continue;
            }

            $contentStatus = ContentStatus::tryFrom($status);
            if ($contentStatus instanceof ContentStatus) {
                $snapshots[$contentUid] = $contentStatus;
            }
        }

        return $snapshots;
    }
}
