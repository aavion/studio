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

final readonly class PackageAssetRebuildDispatcher
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    /**
     * @return OperationResult<array{trigger: string, environment: string, deferred: bool}>
     */
    public function dispatch(string $environment, string $trigger): OperationResult
    {
        $environment = '' === trim($environment) ? 'prod' : trim($environment);
        $trigger = '' === trim($trigger) ? 'package_lifecycle' : trim($trigger);

        try {
            $this->messageBus->dispatch(new PackageAssetRebuildMessage($environment, $trigger));
        } catch (Throwable $error) {
            return OperationResult::failed([
                OperationIssue::create(
                    MessageCode::PACKAGE_ASSET_REBUILD_QUEUE_FAILED,
                    MessageKey::PACKAGE_ASSET_REBUILD_QUEUE_FAILED,
                    ['%trigger%' => $trigger],
                    [
                        'trigger' => $trigger,
                        'environment' => $environment,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                    MessageLevel::Error,
                ),
            ], [
                'trigger' => $trigger,
                'environment' => $environment,
                'deferred' => true,
            ]);
        }

        return OperationResult::success([
            'trigger' => $trigger,
            'environment' => $environment,
            'deferred' => true,
        ], [
            'trigger' => $trigger,
            'environment' => $environment,
            'deferred' => true,
        ], [
            Message::info(
                MessageCode::PACKAGE_ASSET_REBUILD_QUEUED,
                MessageKey::PACKAGE_ASSET_REBUILD_QUEUED,
                ['%trigger%' => $trigger],
                ['trigger' => $trigger, 'environment' => $environment, 'deferred' => true],
            ),
        ]);
    }
}
