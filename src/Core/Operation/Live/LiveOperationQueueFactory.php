<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Operation\ActionQueue;
use App\Core\Operation\OperationMessageKey;
use App\Core\Workflow\WorkflowResult;

final readonly class LiveOperationQueueFactory
{
    public const BACKEND_CACHE_CLEAR = 'backend.cache_clear';
    public const EXTENSION_DISCOVERY = 'extension.discovery';
    public const EXTENSION_ASSET_REBUILD = 'extension.asset_rebuild';
    public const EXTENSION_LIFECYCLE = 'extension.lifecycle';
    public const EXTENSION_INSTALL_VERIFY = 'extension.install.verify';
    public const EXTENSION_INSTALL_APPLY = 'extension.install.apply';
    public const EXTENSION_OPERATION = 'extension.operation';
    public const ACL_GROUP_APPLY = 'acl.group.apply';
    public const SETUP_APPLY = 'setup.apply';
    public const GEOIP_DATABASE_UPDATE = 'geoip.database_update';

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
                CommonMessageCode::E_INVALID_ARGUMENT,
                OperationMessageKey::OPERATION_UNKNOWN,
                ['%operation%' => $operation],
                ['operation' => $operation],
            ),
        ], ['operation' => $operation]);
    }
}
