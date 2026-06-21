<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ExtensionApiEndpointPath;
use App\Core\Message\MessageException;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\ExtensionApiContributionGuard;
use App\Core\Extension\ExtensionScope;
use App\Entity\Extension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ExtensionApiContributionGuardTest extends TestCase
{
    public function testItAcceptsExtensionApiContributionNamespace(): void
    {
        $extension = $this->extension('demo-module');

        ExtensionApiContributionGuard::assertEndpoint($extension, new ApiEndpointDefinition(
            $extension->extensionName(),
            'GET',
            ExtensionApiEndpointPath::path($extension->extensionName(), 'demo'),
            'api_v1_endpoint_dispatch',
            'getDemoModuleContribution',
            'Return demo contribution.',
            'extensions.demo-module.demo',
            ['extensions-demo-module-demo'],
        ));

        self::addToAssertionCount(1);
    }

    public function testItRejectsWrongExtensionNamespaces(): void
    {
        $extension = $this->extension('demo-module');

        $this->expectException(MessageException::class);

        ExtensionApiContributionGuard::assertEndpoint($extension, new ApiEndpointDefinition(
            $extension->extensionName(),
            'GET',
            '/api/v1/ext/demo-module/demo',
            'api_v1_endpoint_dispatch',
            'getDemoModuleContribution',
            'Return demo contribution.',
            'extensions.demo-module.demo',
            ['extensions-demo-module-demo'],
        ));
    }

    public function testItRejectsForeignEndpointOwners(): void
    {
        $extension = $this->extension('demo-module');

        $this->expectException(MessageException::class);

        ExtensionApiContributionGuard::assertEndpoint($extension, new ApiEndpointDefinition(
            'system',
            'GET',
            ExtensionApiEndpointPath::path($extension->extensionName(), 'demo'),
            'api_v1_endpoint_dispatch',
            'getDemoModuleContribution',
            'Return demo contribution.',
            'extensions.demo-module.demo',
            ['extensions-demo-module-demo'],
        ));
    }

    public function testItRejectsExtensionEndpointPatternsOutsideOwnedNamespace(): void
    {
        $extension = $this->extension('demo-module');

        $this->expectException(MessageException::class);

        ExtensionApiContributionGuard::assertEndpoint($extension, new ApiEndpointDefinition(
            $extension->extensionName(),
            'GET',
            ExtensionApiEndpointPath::path($extension->extensionName(), 'demo'),
            'api_v1_endpoint_dispatch',
            'getDemoModuleContribution',
            'Return demo contribution.',
            'extensions.demo-module.demo',
            ['extensions-demo-module-demo'],
            pathPattern: '#^/api/v1/.*$#',
        ));
    }

    public function testItRejectsExtensionEndpointPatternsWithEscapingAlternation(): void
    {
        $extension = $this->extension('demo-module');

        $this->expectException(MessageException::class);

        ExtensionApiContributionGuard::assertEndpoint($extension, new ApiEndpointDefinition(
            $extension->extensionName(),
            'GET',
            ExtensionApiEndpointPath::path($extension->extensionName(), 'demo'),
            'api_v1_endpoint_dispatch',
            'getDemoModuleContribution',
            'Return demo contribution.',
            'extensions.demo-module.demo',
            ['extensions-demo-module-demo'],
            pathPattern: '#^/api/v1/extensions/demo-module/.*|^/api/v1/extensions/other/#',
        ));
    }

    public function testItAllowsGroupedExtensionEndpointPatternAlternationInsideOwnedNamespace(): void
    {
        $extension = $this->extension('demo-module');

        ExtensionApiContributionGuard::assertEndpoint($extension, new ApiEndpointDefinition(
            $extension->extensionName(),
            'GET',
            ExtensionApiEndpointPath::path($extension->extensionName(), 'demo'),
            'api_v1_endpoint_dispatch',
            'getDemoModuleContribution',
            'Return demo contribution.',
            'extensions.demo-module.demo',
            ['extensions-demo-module-demo'],
            pathPattern: '#^/api/v1/extensions/demo-module/(demo|status)$#',
        ));

        self::addToAssertionCount(1);
    }

    public function testItRejectsForeignHandlerNamespaces(): void
    {
        $extension = $this->extension('demo-module');

        $this->expectException(MessageException::class);

        ExtensionApiContributionGuard::assertEndpoint($extension, new ApiEndpointDefinition(
            $extension->extensionName(),
            'GET',
            ExtensionApiEndpointPath::path($extension->extensionName(), 'demo'),
            'api_v1_endpoint_dispatch',
            'getDemoModuleContribution',
            'Return demo contribution.',
            'ext.demo-module.demo',
            ['extensions-demo-module-demo'],
        ));
    }

    public function testItRejectsForeignTagNamespaces(): void
    {
        $extension = $this->extension('demo-module');

        $this->expectException(MessageException::class);

        ExtensionApiContributionGuard::assertEndpoint($extension, new ApiEndpointDefinition(
            $extension->extensionName(),
            'GET',
            ExtensionApiEndpointPath::path($extension->extensionName(), 'demo'),
            'api_v1_endpoint_dispatch',
            'getDemoModuleContribution',
            'Return demo contribution.',
            'extensions.demo-module.demo',
            ['system-demo'],
        ));
    }

    public function testItRejectsMissingExtensionTags(): void
    {
        $extension = $this->extension('demo-module');

        $this->expectException(MessageException::class);

        ExtensionApiContributionGuard::assertEndpoint($extension, new ApiEndpointDefinition(
            $extension->extensionName(),
            'GET',
            ExtensionApiEndpointPath::path($extension->extensionName(), 'demo'),
            'api_v1_endpoint_dispatch',
            'getDemoModuleContribution',
            'Return demo contribution.',
            'extensions.demo-module.demo',
        ));
    }

    private function extension(string $name): Extension
    {
        return new Extension(
            Uuid::v7()->toRfc4122(),
            [ExtensionScope::Module],
            $name,
            'extensions/'.$name,
            ExtensionStatus::Active,
        );
    }
}
