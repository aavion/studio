<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

use App\Core\Id\UuidFactory;
use App\Core\Manifest\Manifest;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageDependencyResolver;
use App\Core\Package\PackageScope;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;

final readonly class PackageReplacementPreflight
{
    public function __construct(
        private PackageDependencyResolver $dependencyResolver,
        private UuidFactory $uuidFactory = new UuidFactory(),
    ) {
    }

    /**
     * @param list<PackageScope> $scopes
     *
     * @return WorkflowResult<array{packages: list<ExtensionPackage>, dependencies: list<array<string, mixed>>}>
     */
    public function dependencies(Manifest $manifest, string $slug, array $scopes): WorkflowResult
    {
        $version = trim((string) $manifest->get('PACKAGE_VERSION', ''));
        $package = new ExtensionPackage(
            $this->uuidFactory->generate(),
            $scopes,
            $slug,
            'packages/'.$slug,
            ExtensionPackageStatus::Inactive,
            ['manifest' => $manifest->all()],
            manifestVersion: '' !== $version ? $version : null,
            installedVersion: '' !== $version ? $version : null,
        );

        return $this->dependencyResolver->resolve($package);
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    public function packageNameList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $packageName): bool => is_string($packageName) && '' !== trim($packageName),
        ));
    }
}
