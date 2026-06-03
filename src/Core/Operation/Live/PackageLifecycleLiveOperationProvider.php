<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Operation\ActionQueue;
use App\Core\Operation\Process\RunCommandAction;
use App\Core\Process\PhpCliBinaryResolver;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class PackageLifecycleLiveOperationProvider implements LiveOperationQueueProviderInterface
{
    public function __construct(
        private KernelInterface $kernel,
        private PhpCliBinaryResolver $phpCliBinaryResolver,
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
                    MessageCode::E_INVALID_ARGUMENT,
                    MessageKey::OPERATION_INVALID_PAYLOAD,
                    ['%operation%' => $this->operation()],
                    ['operation' => $this->operation(), 'payload_keys' => array_keys($payload)],
                ),
            ], ['operation' => $this->operation(), 'payload_keys' => array_keys($payload)]);
        }

        $environment = $this->environment($payload);

        return WorkflowResult::success(ActionQueue::create('package lifecycle', [
            new RunCommandAction([
                ...$this->phpCliCommandPrefix(),
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
            'trigger' => $this->trigger($payload),
        ]));
    }

    /**
     * @return list<string>
     */
    private function phpCliCommandPrefix(): array
    {
        $resolution = $this->phpCliBinaryResolver->resolve($this->kernel->getProjectDir());

        return $resolution->isAvailable() ? $resolution->commandPrefix() : ['php'];
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
