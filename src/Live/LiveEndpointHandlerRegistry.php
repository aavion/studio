<?php

declare(strict_types=1);

namespace App\Live;

final readonly class LiveEndpointHandlerRegistry
{
    /**
     * @param iterable<LiveEndpointHandlerInterface|LiveEndpointHandlerProviderInterface> $handlers
     */
    public function __construct(private iterable $handlers)
    {
    }

    public function handler(string $key): ?LiveEndpointHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler instanceof LiveEndpointHandlerInterface && $handler->liveEndpointHandlerKey() === $key) {
                return $handler;
            }

            if (!$handler instanceof LiveEndpointHandlerProviderInterface) {
                continue;
            }

            foreach ($handler->liveEndpointHandlers() as $providedHandler) {
                if ($providedHandler->liveEndpointHandlerKey() === $key) {
                    return $providedHandler;
                }
            }
        }

        return null;
    }
}
