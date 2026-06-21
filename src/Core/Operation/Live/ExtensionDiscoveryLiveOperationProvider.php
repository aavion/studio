<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Operation\ActionQueue;
use App\Core\Operation\Process\PhpCliUnavailableAction;
use App\Core\Operation\Process\RunCommandAction;
use App\Core\Process\PhpCliBinaryManager;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class ExtensionDiscoveryLiveOperationProvider implements LiveOperationQueueProviderInterface
{
    public function __construct(
        private KernelInterface $kernel,
        private PhpCliBinaryManager $phpCliBinaryManager,
    )
    {
    }

    public function operation(): string
    {
        return LiveOperationQueueFactory::EXTENSION_DISCOVERY;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<ActionQueue>
     */
    public function create(array $payload = []): WorkflowResult
    {
        $environment = $this->environment($payload);
        $trigger = $this->trigger($payload);
        $resolution = $this->phpCliBinaryManager->resolve($this->kernel->getProjectDir(), $environment, persistPreference: true);

        if (!$resolution->isAvailable()) {
            return WorkflowResult::failed([
                PhpCliUnavailableAction::message('extensions:discover', $resolution->reason(), [
                    'environment' => $environment,
                    'trigger' => $trigger,
                ]),
            ], [
                'environment' => $environment,
                'trigger' => $trigger,
            ]);
        }

        return WorkflowResult::success(ActionQueue::create('extension discovery', [
            new RunCommandAction([
                ...$resolution->commandPrefix(),
                $this->kernel->getProjectDir().'/bin/console',
                'extensions:discover',
                '--run-now',
                '--trigger='.$trigger,
                '--env='.$environment,
                '--no-interaction',
            ], $this->kernel->getProjectDir(), timeout: 300.0),
        ], context: [
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
