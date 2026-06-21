<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;
use App\Entity\SchedulerTask;
use App\Entity\SchedulerTaskRun;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ExtensionPurger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExtensionLifecycleStore $store,
        private ExtensionLifecycleCleanupRunnerInterface $cleanupRunner,
    ) {
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function purge(string $extensionName): WorkflowResult
    {
        $extension = $this->store->extension($extensionName);

        if (null === $extension) {
            return $this->extensionNotFound($extensionName);
        }

        if (ExtensionStatus::Removed !== $extension->status()) {
            return $this->statusBlocked($extension, 'extension.purge');
        }

        $cleanup = $this->cleanupRunner->cleanup($extension);

        if (!$cleanup->isSuccess()) {
            return WorkflowResult::failed($cleanup->issues(), [
                'extension' => $extensionName,
                'cleanup_context' => $cleanup->context(),
            ], $cleanup->messages());
        }

        foreach ($this->entityManager->getRepository(SchedulerTask::class)->findBy(['source' => $extensionName]) as $task) {
            if ($task instanceof SchedulerTask) {
                foreach ($this->entityManager->getRepository(SchedulerTaskRun::class)->findBy(['task' => $task]) as $run) {
                    $this->entityManager->remove($run);
                }
                $this->entityManager->remove($task);
            }
        }
        $this->entityManager->remove($extension);
        $this->entityManager->flush();

        return WorkflowResult::success([
            'extension' => $extensionName,
            'changes' => [[
                'extension' => $extensionName,
                'action' => 'purged',
                'status' => 'deleted',
            ]],
        ], [
            'extension' => $extensionName,
            'cleanup_context' => $cleanup->context(),
        ], [
            ...$cleanup->messages(),
            Message::create(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_PURGED,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_PURGED,
                ['%extension%' => $extensionName],
                ['extension' => $extensionName],
                MessageLevel::Success,
            ),
        ]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function extensionNotFound(string $extensionName): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::create(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_EXTENSION_NOT_FOUND,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_EXTENSION_NOT_FOUND,
                ['%extension%' => $extensionName],
                ['extension' => $extensionName],
                MessageLevel::Warning,
            ),
        ]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function statusBlocked(Extension $extension, string $operation): WorkflowResult
    {
        return WorkflowResult::blocked([
            Message::create(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_STATUS_BLOCKED,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_STATUS_BLOCKED,
                ['%extension%' => $extension->extensionName(), '%status%' => $extension->status()->value],
                ['extension' => $extension->extensionName(), 'status' => $extension->status()->value, 'operation' => $operation],
                MessageLevel::Warning,
            ),
        ]);
    }
}
