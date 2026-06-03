<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Operation\ActionQueue;
use App\Core\Operation\Process\PhpCliUnavailableAction;
use App\Core\Operation\Process\RunCommandAction;
use App\Core\Process\PhpCliBinaryResolver;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class PackageDiscoveryLiveOperationProvider implements LiveOperationQueueProviderInterface
{
    public function __construct(
        private KernelInterface $kernel,
        private PhpCliBinaryResolver $phpCliBinaryResolver,
    )
    {
    }

    public function operation(): string
    {
        return LiveOperationQueueFactory::PACKAGE_DISCOVERY;
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
        $resolution = $this->phpCliBinaryResolver->resolve($this->kernel->getProjectDir());

        if (!$resolution->isAvailable()) {
            return WorkflowResult::failed([
                PhpCliUnavailableAction::message('studio:packages:discover', $resolution->reason(), [
                    'environment' => $environment,
                    'trigger' => $trigger,
                ]),
            ], [
                'environment' => $environment,
                'trigger' => $trigger,
            ]);
        }

        return WorkflowResult::success(ActionQueue::create('package discovery', [
            new RunCommandAction([
                ...$resolution->commandPrefix(),
                $this->kernel->getProjectDir().'/bin/console',
                'studio:packages:discover',
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
