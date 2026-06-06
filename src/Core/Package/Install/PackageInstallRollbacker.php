<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageDiscoveryRunner;
use Throwable;

final readonly class PackageInstallRollbacker
{
    public function __construct(
        private PackageDiscoveryRunner $discoveryRunner,
        private PackageInstallFilesystem $filesystem,
        private PackageInstallRegistry $registry,
    ) {
    }

    /**
     * @param array<string, ExtensionPackageStatus> $previousStatuses
     *
     * @return list<Message>
     */
    public function previousPackage(
        string $slug,
        string $target,
        string $backup,
        array $previousStatuses,
    ): array {
        try {
            $this->filesystem->removePath($target);

            if ($this->filesystem->pathExists($backup)) {
                $this->filesystem->ensureDirectory(dirname($target));
                $this->filesystem->movePath($backup, $target);
            }

            $discoveryMessages = [];
            if ($this->filesystem->pathExists($target)) {
                $rollbackDiscovery = ($this->discoveryRunner)('package_install_rollback');
                if (!$rollbackDiscovery->isSuccess()) {
                    $discoveryMessages = [...$rollbackDiscovery->messages(), ...$rollbackDiscovery->issues()];
                }
            }

            $statusMessages = $this->registry->restoreStatuses($previousStatuses);

            return [...$discoveryMessages, ...$statusMessages];
        } catch (Throwable $error) {
            return [
                Message::exception(
                    MessageCode::OPERATION_EXCEPTION,
                    MessageKey::OPERATION_EXCEPTION,
                    context: [
                        'package' => $slug,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                        'rollback' => true,
                    ],
                ),
            ];
        }
    }
}
