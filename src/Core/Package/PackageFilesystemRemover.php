<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Operation\Filesystem\RemovePathAction;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use Throwable;

final readonly class PackageFilesystemRemover
{
    public function __construct(
        private string $projectDir,
        private PackageLifecycleStore $store,
    ) {
    }

    /**
     * @return WorkflowResult<array{path: string, removed: bool}>
     */
    public function remove(ExtensionPackage $package): WorkflowResult
    {
        if (!$this->store->isManagedFilesystemPackage($package)) {
            return WorkflowResult::blocked([
                Message::create(
                    PackageMessageCode::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                    PackageMessageKey::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                    ['%package%' => $package->packageName(), '%status%' => $package->status()->value],
                    ['package' => $package->packageName(), 'path' => $package->path(), 'reason' => 'not_filesystem_package'],
                    MessageLevel::Warning,
                ),
            ]);
        }

        try {
            return (new RemovePathAction($this->projectDir, $package->path()))->execute();
        } catch (Throwable $error) {
            return WorkflowResult::failed([
                Message::exception(
                    OperationMessageCode::OPERATION_EXCEPTION,
                    OperationMessageKey::OPERATION_EXCEPTION,
                    context: [
                        'package' => $package->packageName(),
                        'path' => $package->path(),
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                ),
            ]);
        }
    }
}
