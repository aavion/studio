<?php

declare(strict_types=1);

namespace App\Core\Extension\Install;

use App\Core\Id\UuidFactory;
use App\Core\Manifest\Manifest;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\ExtensionDependencyResolver;
use App\Core\Extension\ExtensionScope;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;

final readonly class ExtensionReplacementPreflight
{
    public function __construct(
        private ExtensionDependencyResolver $dependencyResolver,
        private UuidFactory $uuidFactory = new UuidFactory(),
    ) {
    }

    /**
     * @param list<ExtensionScope> $scopes
     *
     * @return WorkflowResult<array{extensions: list<Extension>, dependencies: list<array<string, mixed>>}>
     */
    public function dependencies(Manifest $manifest, string $slug, array $scopes): WorkflowResult
    {
        $version = trim((string) $manifest->get('EXTENSION_VERSION', ''));
        $extension = new Extension(
            $this->uuidFactory->generate(),
            $scopes,
            $slug,
            'extensions/'.$slug,
            ExtensionStatus::Inactive,
            ['manifest' => $manifest->all()],
            manifestVersion: '' !== $version ? $version : null,
            installedVersion: '' !== $version ? $version : null,
        );

        return $this->dependencyResolver->resolve($extension);
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    public function extensionNameList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $extensionName): bool => is_string($extensionName) && '' !== trim($extensionName),
        ));
    }
}
