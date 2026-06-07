<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

final readonly class ApiEndpointHandlerRegistry
{
    /**
     * @param iterable<ApiEndpointHandlerInterface|ApiEndpointHandlerProviderInterface> $handlers
     */
    public function __construct(private iterable $handlers)
    {
    }

    public function handler(string $key): ?ApiEndpointHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler instanceof ApiEndpointHandlerInterface && $handler->apiEndpointHandlerKey() === $key) {
                return $handler;
            }

            if (!$handler instanceof ApiEndpointHandlerProviderInterface) {
                continue;
            }

            foreach ($handler->apiEndpointHandlers() as $providedHandler) {
                if ($providedHandler->apiEndpointHandlerKey() === $key) {
                    return $providedHandler;
                }
            }
        }

        return null;
    }
}
