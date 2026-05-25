<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Operation\Filesystem\RemovePathAction;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class PackageRemover
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PackageLifecycleAssetRebuilderInterface $assetRebuilder,
        private PackageLifecycleCleanupRunnerInterface $cleanupRunner,
        private string $projectDir,
    ) {
    }

    /**
     * @return OperationResult<array<string, mixed>>
     */
    public function planRemoval(string $packageName): OperationResult
    {
        $package = $this->package($packageName);

        if (null === $package) {
            return $this->packageNotFound($packageName);
        }

        return OperationResult::success([
            'package' => $packageName,
            'changes' => $this->plannedRemovalChanges($package),
        ], [
            'package' => $packageName,
            'path' => $package->path(),
        ]);
    }

    /**
     * @return OperationResult<array<string, mixed>>
     */
    public function remove(string $packageName, string $environment, bool $rebuildAssets = true): OperationResult
    {
        $package = $this->package($packageName);

        if (null === $package) {
            return $this->packageNotFound($packageName);
        }

        $messages = [];
        $changes = [];
        $previousStatus = $package->status();

        if (ExtensionPackageStatus::Active === $package->status() && $package->deactivate()) {
            $changes[] = $this->change($package, 'deactivated');
            $messages[] = Message::info(
                MessageCode::PACKAGE_LIFECYCLE_DEACTIVATED,
                MessageKey::PACKAGE_LIFECYCLE_DEACTIVATED,
                ['%package%' => $packageName],
                ['package' => $packageName],
            );
        }

        $filesystem = $this->removeFilesystemPackage($package);
        $messages = [...$messages, ...$filesystem->messages()];

        if (!$filesystem->isSuccess()) {
            $package->restoreStatus($previousStatus);

            return OperationResult::failed($filesystem->issues(), [
                'package' => $packageName,
                'path' => $package->path(),
                'changes' => $changes,
                'filesystem_context' => $filesystem->context(),
            ], $messages);
        }

        if ($package->markRemoved($this->removedMetadata($package, $filesystem->context()))) {
            $changes[] = $this->change($package, 'removed');
            $messages[] = Message::info(
                MessageCode::PACKAGE_LIFECYCLE_REMOVED,
                MessageKey::PACKAGE_LIFECYCLE_REMOVED,
                ['%package%' => $packageName],
                ['package' => $packageName, 'path' => $package->path()],
            );
        }

        $this->entityManager->flush();

        if ([] !== $changes && $rebuildAssets) {
            $rebuild = $this->assetRebuilder->rebuild($environment);
            $messages = [...$messages, ...$rebuild->messages()];

            if (!$rebuild->isSuccess()) {
                return OperationResult::failed($rebuild->issues(), [
                    'package' => $packageName,
                    'path' => $package->path(),
                    'changes' => $changes,
                    'asset_rebuild' => true,
                    'rolled_back' => false,
                    'asset_rebuild_context' => $rebuild->context(),
                ], $messages);
            }
        }

        return OperationResult::success([
            'package' => $packageName,
            'path' => $package->path(),
            'changes' => $changes,
            'asset_rebuild' => [] !== $changes && $rebuildAssets,
            'rolled_back' => false,
        ], [
            'package' => $packageName,
            'path' => $package->path(),
            'changes' => $changes,
            'filesystem_context' => $filesystem->context(),
        ], $messages);
    }

    /**
     * @return OperationResult<array<string, mixed>>
     */
    public function purge(string $packageName): OperationResult
    {
        $package = $this->package($packageName);

        if (null === $package) {
            return $this->packageNotFound($packageName);
        }

        $cleanup = $this->cleanupRunner->cleanup($package);

        if (!$cleanup->isSuccess()) {
            return OperationResult::failed($cleanup->issues(), [
                'package' => $packageName,
                'cleanup_context' => $cleanup->context(),
            ], $cleanup->messages());
        }

        $this->entityManager->remove($package);
        $this->entityManager->flush();

        return OperationResult::success([
            'package' => $packageName,
            'changes' => [[
                'package' => $packageName,
                'action' => 'purged',
                'status' => 'deleted',
            ]],
        ], [
            'package' => $packageName,
            'cleanup_context' => $cleanup->context(),
        ], [
            ...$cleanup->messages(),
            Message::info(
                MessageCode::PACKAGE_LIFECYCLE_PURGED,
                MessageKey::PACKAGE_LIFECYCLE_PURGED,
                ['%package%' => $packageName],
                ['package' => $packageName],
            ),
        ]);
    }

    private function package(string $packageName): ?ExtensionPackage
    {
        $package = $this->entityManager->getRepository(ExtensionPackage::class)->findOneBy([
            'packageName' => $packageName,
        ]);

        return $package instanceof ExtensionPackage ? $package : null;
    }

    /**
     * @return OperationResult<array{path: string, removed: bool}>
     */
    private function removeFilesystemPackage(ExtensionPackage $package): OperationResult
    {
        if (!str_starts_with($package->path(), 'packages/')) {
            return OperationResult::blocked([
                OperationIssue::create(
                    MessageCode::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                    MessageKey::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                    ['%package%' => $package->packageName(), '%status%' => $package->status()->value],
                    ['package' => $package->packageName(), 'path' => $package->path(), 'reason' => 'not_filesystem_package'],
                    MessageLevel::Warning,
                ),
            ]);
        }

        try {
            return (new RemovePathAction($this->projectDir, $package->path()))->execute();
        } catch (Throwable $error) {
            return OperationResult::failed([
                OperationIssue::create(
                    MessageCode::OPERATION_EXCEPTION,
                    MessageKey::OPERATION_EXCEPTION,
                    context: [
                        'package' => $package->packageName(),
                        'path' => $package->path(),
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                    level: MessageLevel::Error,
                ),
            ]);
        }
    }

    /**
     * @return list<array{package: string, action: string, status: string}>
     */
    private function plannedRemovalChanges(ExtensionPackage $package): array
    {
        $changes = [];

        if (ExtensionPackageStatus::Active === $package->status()) {
            $changes[] = ['package' => $package->packageName(), 'action' => 'deactivated', 'status' => ExtensionPackageStatus::Inactive->value];
        }

        if (ExtensionPackageStatus::Removed !== $package->status()) {
            $changes[] = ['package' => $package->packageName(), 'action' => 'removed', 'status' => ExtensionPackageStatus::Removed->value];
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $filesystemContext
     *
     * @return array<string, mixed>
     */
    private function removedMetadata(ExtensionPackage $package, array $filesystemContext): array
    {
        return [
            ...$package->metadata(),
            'registry_state' => 'removed',
            'removed_path' => $package->path(),
            'filesystem' => $filesystemContext,
        ];
    }

    /**
     * @return array{package: string, action: string, status: string}
     */
    private function change(ExtensionPackage $package, string $action): array
    {
        return [
            'package' => $package->packageName(),
            'action' => $action,
            'status' => $package->status()->value,
        ];
    }

    /**
     * @return OperationResult<array<string, mixed>>
     */
    private function packageNotFound(string $packageName): OperationResult
    {
        return OperationResult::invalid([
            OperationIssue::create(
                MessageCode::PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND,
                MessageKey::PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND,
                ['%package%' => $packageName],
                ['package' => $packageName],
                MessageLevel::Warning,
            ),
        ]);
    }
}
