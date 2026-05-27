<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Operation\ActionQueue;
use App\Core\Operation\Process\RunCommandAction;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class BackendCacheClearLiveOperationProvider implements LiveOperationQueueProviderInterface
{
    public function __construct(private KernelInterface $kernel)
    {
    }

    public function operation(): string
    {
        return LiveOperationQueueFactory::BACKEND_CACHE_CLEAR;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<ActionQueue>
     */
    public function create(array $payload = []): WorkflowResult
    {
        $environment = $this->environment($payload);

        return WorkflowResult::success(ActionQueue::create('backend cache clear', [
            new RunCommandAction([
                PHP_BINARY,
                $this->kernel->getProjectDir().'/bin/console',
                'cache:clear',
                '--env='.$environment,
                '--no-interaction',
            ], $this->kernel->getProjectDir(), timeout: 300.0),
        ], context: [
            'environment' => $environment,
            'trigger' => $this->trigger($payload),
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
