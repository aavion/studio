<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Database\TablePrefix;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final readonly class ExtensionDiscoveryDispatcher
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private WorkflowResultMessageReporterInterface $messageReporter,
        private ?Connection $connection = null,
    ) {
    }

    /**
     * @return WorkflowResult<array{trigger: string, deferred: bool}>
     */
    public function dispatch(string $trigger = 'manual'): WorkflowResult
    {
        $trigger = '' === trim($trigger) ? 'manual' : trim($trigger);
        $context = ['operation' => 'extension.discovery.dispatch', 'trigger' => $trigger];

        if (!$this->messengerStorageReady()) {
            return $this->report(WorkflowResult::failed([
                Message::warning(
                    ExtensionMessageCode::EXTENSION_DISCOVERY_QUEUE_FAILED,
                    ExtensionMessageKey::EXTENSION_DISCOVERY_QUEUE_FAILED,
                    ['%trigger%' => $trigger],
                    ['trigger' => $trigger, 'reason' => 'messenger_storage_unavailable'],
                ),
            ], [
                'trigger' => $trigger,
                'deferred' => true,
            ]), $context);
        }

        try {
            $this->messageBus->dispatch(new ExtensionDiscoveryMessage($trigger));
        } catch (Throwable $error) {
            return $this->report(WorkflowResult::failed([
                Message::exception(
                    ExtensionMessageCode::EXTENSION_DISCOVERY_QUEUE_FAILED,
                    ExtensionMessageKey::EXTENSION_DISCOVERY_QUEUE_FAILED,
                    ['%trigger%' => $trigger],
                    [
                        'trigger' => $trigger,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                ),
            ], [
                'trigger' => $trigger,
                'deferred' => true,
            ]), $context);
        }

        return $this->report(WorkflowResult::success([
            'trigger' => $trigger,
            'deferred' => true,
        ], [
            'trigger' => $trigger,
            'deferred' => true,
        ], [
            Message::create(
                ExtensionMessageCode::EXTENSION_DISCOVERY_QUEUED,
                ExtensionMessageKey::EXTENSION_DISCOVERY_QUEUED,
                ['%trigger%' => $trigger],
                ['trigger' => $trigger, 'deferred' => true],
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
