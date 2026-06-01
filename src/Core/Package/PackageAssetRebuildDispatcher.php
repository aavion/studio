<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Workflow\WorkflowResult;
use App\Database\TablePrefix;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final readonly class PackageAssetRebuildDispatcher
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private WorkflowResultMessageReporterInterface $messageReporter,
        private ?Connection $connection = null,
    )
    {
    }

    /**
     * @return WorkflowResult<array{trigger: string, environment: string, deferred: bool}>
     */
    public function dispatch(string $environment, string $trigger): WorkflowResult
    {
        $environment = '' === trim($environment) ? 'prod' : trim($environment);
        $trigger = '' === trim($trigger) ? 'package_lifecycle' : trim($trigger);
        $context = ['operation' => 'package.asset_rebuild.dispatch', 'environment' => $environment, 'trigger' => $trigger];

        if (!$this->messengerStorageReady()) {
            return $this->report(WorkflowResult::failed([
                Message::warning(
                    MessageCode::PACKAGE_ASSET_REBUILD_QUEUE_FAILED,
                    MessageKey::PACKAGE_ASSET_REBUILD_QUEUE_FAILED,
                    ['%trigger%' => $trigger],
                    ['trigger' => $trigger, 'environment' => $environment, 'reason' => 'messenger_storage_unavailable'],
                ),
            ], [
                'trigger' => $trigger,
                'environment' => $environment,
                'deferred' => true,
            ]), $context);
        }

        try {
            $this->messageBus->dispatch(new PackageAssetRebuildMessage($environment, $trigger));
        } catch (Throwable $error) {
            return $this->report(WorkflowResult::failed([
                Message::exception(
                    MessageCode::PACKAGE_ASSET_REBUILD_QUEUE_FAILED,
                    MessageKey::PACKAGE_ASSET_REBUILD_QUEUE_FAILED,
                    ['%trigger%' => $trigger],
                    [
                        'trigger' => $trigger,
                        'environment' => $environment,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                ),
            ], [
                'trigger' => $trigger,
                'environment' => $environment,
                'deferred' => true,
            ]), $context);
        }

        return $this->report(WorkflowResult::success([
            'trigger' => $trigger,
            'environment' => $environment,
            'deferred' => true,
        ], [
            'trigger' => $trigger,
            'environment' => $environment,
            'deferred' => true,
        ], [
            Message::create(
                MessageCode::PACKAGE_ASSET_REBUILD_QUEUED,
                MessageKey::PACKAGE_ASSET_REBUILD_QUEUED,
                ['%trigger%' => $trigger],
                ['trigger' => $trigger, 'environment' => $environment, 'deferred' => true],
                MessageLevel::Success,
            ),
        ]), $context);
    }

    private function report(WorkflowResult $result, array $context): WorkflowResult
    {
        return $this->messageReporter->report($result, $context);
    }

    private function messengerStorageReady(): bool
    {
        if (!$this->connection instanceof Connection) {
            return true;
        }

        try {
            return in_array(TablePrefix::apply('messenger_messages'), $this->connection->createSchemaManager()->listTableNames(), true);
        } catch (Throwable) {
            return false;
        }
    }
}
