<?php

declare(strict_types=1);

namespace App\Core\Extension\Contribution;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Endpoint\ApiEndpointHandlerProviderInterface;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use App\Entity\Extension;
use App\Live\LiveEndpointDefinition;
use App\Live\LiveEndpointHandlerInterface;
use App\Live\LiveEndpointHandlerProviderInterface;
use App\Live\LiveEndpointProviderInterface;

final class ExtensionRuntimeEndpointContributions implements ApiEndpointProviderInterface, ApiEndpointHandlerProviderInterface, LiveEndpointProviderInterface, LiveEndpointHandlerProviderInterface
{
    private array $apiEndpointDefinitions = [];

    private array $apiEndpointHandlers = [];

    private array $liveEndpointDefinitions = [];

    private array $liveEndpointHandlers = [];

    public function addApiEndpoint(Extension $extension, ApiEndpointDefinition $definition, ExtensionRuntimeContributionGuard $guard): void
    {
        $guard->assertApiEndpoint($extension, $definition);
        $this->apiEndpointDefinitions[] = $definition;
    }

    public function addApiEndpointHandler(Extension $extension, ApiEndpointHandlerInterface $handler, ExtensionRuntimeContributionGuard $guard): void
    {
        $guard->assertApiEndpointHandler($extension, $handler);
        $this->apiEndpointHandlers[] = $handler;
    }

    public function addLiveEndpoint(Extension $extension, LiveEndpointDefinition $definition, ExtensionRuntimeContributionGuard $guard): void
    {
        $guard->assertLiveEndpoint($extension, $definition);
        $this->liveEndpointDefinitions[] = $definition;
    }

    public function addLiveEndpointHandler(Extension $extension, LiveEndpointHandlerInterface $handler, ExtensionRuntimeContributionGuard $guard): void
    {
        $guard->assertLiveEndpointHandler($extension, $handler);
        $this->liveEndpointHandlers[] = $handler;
    }

    public function apiEndpoints(): array
    {
        return $this->apiEndpointDefinitions;
    }

    public function apiEndpointHandlers(): array
    {
        return $this->apiEndpointHandlers;
    }

    public function liveEndpoints(): array
    {
        return $this->liveEndpointDefinitions;
    }

    public function liveEndpointHandlers(): array
    {
        return $this->liveEndpointHandlers;
    }
}
