<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Operation\ActionQueue;
use App\Core\Operation\OperationMessageKey;
use App\Core\Operation\Process\PhpCliUnavailableAction;
use App\Core\Operation\Process\RunCommandAction;
use App\Core\Process\PhpCliBinaryManager;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class PackageLifecycleLiveOperationProvider implements LiveOperationQueueProviderInterface
{
    public function __construct(
        private KernelInterface $kernel,
        private PhpCliBinaryManager $phpCliBinaryManager,
    )
    {
    }

    public function operation(): string
    {
        return LiveOperationQueueFactory::PACKAGE_LIFECYCLE;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<ActionQueue>
     */
    public function create(array $payload = []): WorkflowResult
    {
        $packageName = $payload['package'] ?? null;
        $action = $payload['action'] ?? null;

        if (!is_string($packageName) || '' === trim($packageName) || !is_string($action) || '' === trim($action)) {
            return WorkflowResult::invalid([
                Message::warning(
                    CommonMessageCode::E_INVALID_ARGUMENT,
                    OperationMessageKey::OPERATION_INVALID_PAYLOAD,
                    ['%operation%' => $this->operation()],
                    ['operation' => $this->operation(), 'payload_keys' => array_keys($payload)],
                ),
            ], ['operation' => $this->operation(), 'payload_keys' => array_keys($payload)]);
        }

        $environment = $this->environment($payload);
        $trigger = $this->trigger($payload);
        $resolution = $this->phpCliBinaryManager->resolve($this->kernel->getProjectDir(), $environment, persistPreference: true);

        if (!$resolution->isAvailable()) {
            return WorkflowResult::failed([
                PhpCliUnavailableAction::message('studio:packages:lifecycle', $resolution->reason(), [
                    'operation' => $this->operation(),
                    'package' => trim($packageName),
                    'action' => trim($action),
                    'environment' => $environment,
                    'trigger' => $trigger,
                ]),
            ], [
                'operation' => $this->operation(),
                'package' => trim($packageName),
                'action' => trim($action),
                'environment' => $environment,
                'trigger' => $trigger,
            ]);
        }

        return WorkflowResult::success(ActionQueue::create('package lifecycle', [
            new RunCommandAction([
                ...$resolution->commandPrefix(),
                $this->kernel->getProjectDir().'/bin/console',
                'studio:packages:lifecycle',
                trim($packageName),
                trim($action),
                '--env='.$environment,
                '--no-interaction',
            ], $this->kernel->getProjectDir(), timeout: 300.0),
        ], context: [
            'operation' => $this->operation(),
            'package' => trim($packageName),
            'action' => trim($action),
            'environment' => $environment,
            'trigger' => $trigger,
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function environment(array $payload): string
    {
        $environment = $payload['environment'] ?? $this->kernel->getEnvironment();

        return is_string($environment) && '' !== trim($environment) ? trim($environment) : $this->kernel->getEnvironment();
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
