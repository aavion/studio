<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\PackageApiEndpointPath;
use App\Core\Message\MessageException;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageApiContributionGuard;
use App\Core\Package\PackageScope;
use App\Entity\ExtensionPackage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PackageApiContributionGuardTest extends TestCase
{
    public function testItAcceptsPackageApiContributionNamespace(): void
    {
        $package = $this->package('demo-module');

        PackageApiContributionGuard::assertEndpoint($package, new ApiEndpointDefinition(
            'package',
            'GET',
            PackageApiEndpointPath::path($package->packageName(), 'demo'),
            'api_v1_endpoint_dispatch',
            'getDemoModuleContribution',
            'Return demo contribution.',
            'packages.demo-module.demo',
        ));

        self::addToAssertionCount(1);
    }

    public function testItRejectsWrongPackageNamespaces(): void
    {
        $package = $this->package('demo-module');

        $this->expectException(MessageException::class);

        PackageApiContributionGuard::assertEndpoint($package, new ApiEndpointDefinition(
            'package',
            'GET',
            '/api/v1/pkg/demo-module/demo',
            'api_v1_endpoint_dispatch',
            'getDemoModuleContribution',
            'Return demo contribution.',
            'packages.demo-module.demo',
        ));
    }

    public function testItRejectsForeignHandlerNamespaces(): void
    {
        $package = $this->package('demo-module');

        $this->expectException(MessageException::class);

        PackageApiContributionGuard::assertEndpoint($package, new ApiEndpointDefinition(
            'package',
            'GET',
            PackageApiEndpointPath::path($package->packageName(), 'demo'),
            'api_v1_endpoint_dispatch',
            'getDemoModuleContribution',
            'Return demo contribution.',
            'pkg.demo-module.demo',
        ));
    }

    private function package(string $name): ExtensionPackage
    {
        return new ExtensionPackage(
            Uuid::v7()->toRfc4122(),
            [PackageScope::Module],
            $name,
            'packages/'.$name,
            ExtensionPackageStatus::Active,
        );
    }
}
