<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Geo\MaxMindGeoIpDatabaseUpdater;
use App\Core\Geo\MaxMindGeoIpUpdateAction;
use App\Core\Operation\ActionQueue;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class MaxMindGeoIpLiveOperationProvider implements LiveOperationQueueProviderInterface
{
    public function __construct(
        private KernelInterface $kernel,
        private MaxMindGeoIpDatabaseUpdater $updater,
    ) {
    }

    public function operation(): string
    {
        return LiveOperationQueueFactory::GEOIP_DATABASE_UPDATE;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<ActionQueue>
     */
    public function create(array $payload = []): WorkflowResult
    {
        $trigger = $this->trigger($payload);

        return WorkflowResult::success(ActionQueue::create('geoip database update', [
            new MaxMindGeoIpUpdateAction($this->updater, $trigger),
        ], context: [
            'operation' => $this->operation(),
            'environment' => $this->environment($payload),
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
