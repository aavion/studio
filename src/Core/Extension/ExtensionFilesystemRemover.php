<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Operation\Filesystem\RemovePathAction;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;
use Throwable;

final readonly class ExtensionFilesystemRemover
{
    public function __construct(
        private string $projectDir,
        private ExtensionLifecycleStore $store,
    ) {
    }

    /**
     * @return WorkflowResult<array{path: string, removed: bool}>
     */
    public function remove(Extension $extension): WorkflowResult
    {
        if (!$this->store->isManagedFilesystemExtension($extension)) {
            return WorkflowResult::blocked([
                Message::create(
                    ExtensionMessageCode::EXTENSION_LIFECYCLE_STATUS_BLOCKED,
                    ExtensionMessageKey::EXTENSION_LIFECYCLE_STATUS_BLOCKED,
                    ['%extension%' => $extension->extensionName(), '%status%' => $extension->status()->value],
                    ['extension' => $extension->extensionName(), 'path' => $extension->path(), 'reason' => 'not_filesystem_extension'],
                    MessageLevel::Warning,
                ),
            ]);
        }

        try {
            return (new RemovePathAction($this->projectDir, $extension->path()))->execute();
        } catch (Throwable $error) {
            return WorkflowResult::failed([
                Message::exception(
                    OperationMessageCode::OPERATION_EXCEPTION,
                    OperationMessageKey::OPERATION_EXCEPTION,
                    context: [
                        'extension' => $extension->extensionName(),
                        'path' => $extension->path(),
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                ),
            ]);
        }
    }
}
