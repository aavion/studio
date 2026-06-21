<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Operation\ActionQueue;
use App\Core\Operation\OperationMessageKey;
use App\Core\Extension\Install\ExtensionZipInstaller;
use App\Core\Extension\Install\ExtensionZipVerifyAction;
use App\Core\Workflow\WorkflowResult;

final readonly class ExtensionInstallVerifyLiveOperationProvider implements LiveOperationQueueProviderInterface
{
    public function __construct(private ExtensionZipInstaller $installer)
    {
    }

    public function operation(): string
    {
        return LiveOperationQueueFactory::EXTENSION_INSTALL_VERIFY;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<ActionQueue>
     */
    public function create(array $payload = []): WorkflowResult
    {
        $installId = $payload['install_id'] ?? null;

        if (!is_string($installId) || '' === trim($installId)) {
            return WorkflowResult::invalid([
                Message::warning(
                    CommonMessageCode::E_INVALID_ARGUMENT,
                    OperationMessageKey::OPERATION_INVALID_PAYLOAD,
                    ['%operation%' => $this->operation()],
                    ['operation' => $this->operation(), 'payload_keys' => array_keys($payload)],
                ),
            ], ['operation' => $this->operation(), 'payload_keys' => array_keys($payload)]);
        }

        return WorkflowResult::success(ActionQueue::create('extension install verification', [
            new ExtensionZipVerifyAction($this->installer, $payload),
        ], context: [
            'operation' => $this->operation(),
            'install_id' => trim($installId),
            'trigger' => $this->trigger($payload),
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function trigger(array $payload): string
    {
        $trigger = $payload['trigger'] ?? 'live_operation';

        return is_string($trigger) && '' !== trim($trigger) ? trim($trigger) : 'live_operation';
    }
}
