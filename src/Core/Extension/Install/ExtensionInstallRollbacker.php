<?php

declare(strict_types=1);

namespace App\Core\Extension\Install;

use App\Content\ContentStatus;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\ExtensionDiscoveryRunner;
use App\Core\Message\Message;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;
use Throwable;

final readonly class ExtensionInstallRollbacker
{
    public function __construct(
        private ExtensionDiscoveryRunner $discoveryRunner,
        private ExtensionInstallFilesystem $filesystem,
        private ExtensionInstallRegistry $registry,
    ) {
    }

    /**
     * @param array<string, ExtensionStatus> $previousStatuses
     * @param array<string, ContentStatus> $contentStatusSnapshots
     *
     * @return list<Message>
     */
    public function previousExtension(
        string $slug,
        string $target,
        string $backup,
        array $previousStatuses,
        array $contentStatusSnapshots = [],
    ): array {
        try {
            $this->filesystem->removePath($target);

            if ($this->filesystem->pathExists($backup)) {
                $this->filesystem->ensureDirectory(dirname($target));
                $this->filesystem->movePath($backup, $target);
            }

            $discoveryMessages = [];
            if ($this->filesystem->pathExists($target)) {
                $rollbackDiscovery = ($this->discoveryRunner)('extension_install_rollback');
                if (!$rollbackDiscovery->isSuccess()) {
                    $discoveryMessages = [...$rollbackDiscovery->messages(), ...$rollbackDiscovery->issues()];
                }
            }

            $statusMessages = $this->registry->restoreStatuses($previousStatuses);
            $contentStatusMessages = $this->registry->restoreContentStatuses($contentStatusSnapshots);

            return [...$discoveryMessages, ...$statusMessages, ...$contentStatusMessages];
        } catch (Throwable $error) {
            return [
                Message::exception(
                    OperationMessageCode::OPERATION_EXCEPTION,
                    OperationMessageKey::OPERATION_EXCEPTION,
                    context: [
                        'extension' => $slug,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                        'rollback' => true,
                    ],
                ),
            ];
        }
    }
}
