<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final readonly class PackageDiscoveryDispatcher
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    /**
     * @return OperationResult<array{trigger: string, deferred: bool}>
     */
    public function dispatch(string $trigger = 'manual'): OperationResult
    {
        $trigger = '' === trim($trigger) ? 'manual' : trim($trigger);

        try {
            $this->messageBus->dispatch(new PackageDiscoveryMessage($trigger));
        } catch (Throwable $error) {
            return OperationResult::failed([
                OperationIssue::create(
                    MessageCode::PACKAGE_DISCOVERY_QUEUE_FAILED,
                    MessageKey::PACKAGE_DISCOVERY_QUEUE_FAILED,
                    ['%trigger%' => $trigger],
                    [
                        'trigger' => $trigger,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                    MessageLevel::Error,
                ),
            ], [
                'trigger' => $trigger,
                'deferred' => true,
            ]);
        }

        return OperationResult::success([
            'trigger' => $trigger,
            'deferred' => true,
        ], [
            'trigger' => $trigger,
            'deferred' => true,
        ], [
            Message::info(
                MessageCode::PACKAGE_DISCOVERY_QUEUED,
                MessageKey::PACKAGE_DISCOVERY_QUEUED,
                ['%trigger%' => $trigger],
                ['trigger' => $trigger, 'deferred' => true],
            ),
        ]);
    }
}
