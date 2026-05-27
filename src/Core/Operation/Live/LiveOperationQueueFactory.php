<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Operation\ActionQueue;
use App\Core\Workflow\WorkflowResult;

final readonly class LiveOperationQueueFactory
{
    public const BACKEND_CACHE_CLEAR = 'backend.cache_clear';
    public const PACKAGE_DISCOVERY = 'package.discovery';
    public const PACKAGE_ASSET_REBUILD = 'package.asset_rebuild';
    public const PACKAGE_LIFECYCLE = 'package.lifecycle';
    public const PACKAGE_INSTALL_VERIFY = 'package.install.verify';
    public const PACKAGE_INSTALL_APPLY = 'package.install.apply';

    /**
     * @param iterable<LiveOperationQueueProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<ActionQueue>
     */
    public function create(string $operation, array $payload = []): WorkflowResult
    {
        foreach ($this->providers as $provider) {
            if (!$provider instanceof LiveOperationQueueProviderInterface || $provider->operation() !== $operation) {
                continue;
            }

            return $provider->create($payload);
        }

        return WorkflowResult::invalid([
            Message::warning(
                MessageCode::E_INVALID_ARGUMENT,
                MessageKey::OPERATION_UNKNOWN,
                ['%operation%' => $operation],
                ['operation' => $operation],
            ),
        ], ['operation' => $operation]);
    }
}
