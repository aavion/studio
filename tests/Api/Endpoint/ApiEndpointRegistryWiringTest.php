<?php

declare(strict_types=1);

namespace App\Tests\Api\Endpoint;

use App\Api\Endpoint\ApiEndpointHandlerRegistry;
use App\Api\Endpoint\ApiEndpointRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ApiEndpointRegistryWiringTest extends KernelTestCase
{
    public function testDefinitionBackedEndpointsHaveRegisteredHandlers(): void
    {
        $container = self::getContainer();
        $endpoints = $container->get(ApiEndpointRegistry::class);
        $handlers = $container->get(ApiEndpointHandlerRegistry::class);
        $missingHandlers = [];

        foreach ($endpoints->endpoints() as $endpoint) {
            if ('api_v1_endpoint_dispatch' !== $endpoint->routeName()) {
                continue;
            }

            $handlerKey = $endpoint->handlerKey();
            if (null === $handlerKey || null === $handlers->handler($handlerKey)) {
                $missingHandlers[] = $endpoint->method().' '.$endpoint->path().' -> '.($handlerKey ?? 'none');
            }
        }

        self::assertSame([], $missingHandlers);
    }

    public function testEndpointDefinitionsUseUniqueMethodsAndPaths(): void
    {
        $endpoints = self::getContainer()->get(ApiEndpointRegistry::class);
        $seen = [];
        $duplicates = [];

        foreach ($endpoints->endpoints() as $endpoint) {
            $key = $endpoint->method().' '.$endpoint->path();
            if (isset($seen[$key])) {
                $duplicates[] = $key;
            }

            $seen[$key] = true;
        }

        self::assertSame([], $duplicates);
    }

    public function testEndpointDefinitionsUseUniqueOperationIds(): void
    {
        $endpoints = self::getContainer()->get(ApiEndpointRegistry::class);
        $seen = [];
        $duplicates = [];

        foreach ($endpoints->endpoints() as $endpoint) {
            $operationId = $endpoint->operationId();
            if (isset($seen[$operationId])) {
                $duplicates[] = $operationId;
            }

            $seen[$operationId] = true;
        }

        self::assertSame([], $duplicates);
    }
}
