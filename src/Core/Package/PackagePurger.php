<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use App\Entity\SchedulerTask;
use App\Entity\SchedulerTaskRun;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PackagePurger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PackageLifecycleStore $store,
        private PackageLifecycleCleanupRunnerInterface $cleanupRunner,
    ) {
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function purge(string $packageName): WorkflowResult
    {
        $package = $this->store->package($packageName);

        if (null === $package) {
            return $this->packageNotFound($packageName);
        }

        if (ExtensionPackageStatus::Removed !== $package->status()) {
            return $this->statusBlocked($package, 'package.purge');
        }

        $cleanup = $this->cleanupRunner->cleanup($package);

        if (!$cleanup->isSuccess()) {
            return WorkflowResult::failed($cleanup->issues(), [
                'package' => $packageName,
                'cleanup_context' => $cleanup->context(),
            ], $cleanup->messages());
        }

        foreach ($this->entityManager->getRepository(SchedulerTask::class)->findBy(['source' => $packageName]) as $task) {
            if ($task instanceof SchedulerTask) {
                foreach ($this->entityManager->getRepository(SchedulerTaskRun::class)->findBy(['task' => $task]) as $run) {
                    $this->entityManager->remove($run);
                }
                $this->entityManager->remove($task);
            }
        }
        $this->entityManager->remove($package);
        $this->entityManager->flush();

        return WorkflowResult::success([
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
            Message::create(
                PackageMessageCode::PACKAGE_LIFECYCLE_PURGED,
                PackageMessageKey::PACKAGE_LIFECYCLE_PURGED,
                ['%package%' => $packageName],
                ['package' => $packageName],
                MessageLevel::Success,
            ),
        ]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function packageNotFound(string $packageName): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::create(
                PackageMessageCode::PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND,
                PackageMessageKey::PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND,
                ['%package%' => $packageName],
                ['package' => $packageName],
                MessageLevel::Warning,
            ),
        ]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function statusBlocked(ExtensionPackage $package, string $operation): WorkflowResult
    {
        return WorkflowResult::blocked([
            Message::create(
                PackageMessageCode::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                PackageMessageKey::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                ['%package%' => $package->packageName(), '%status%' => $package->status()->value],
                ['package' => $package->packageName(), 'status' => $package->status()->value, 'operation' => $operation],
                MessageLevel::Warning,
            ),
        ]);
    }
}
