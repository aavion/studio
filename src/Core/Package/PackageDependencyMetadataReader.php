<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Entity\ExtensionPackage;

final readonly class PackageDependencyMetadataReader
{
    public function __construct(
        private PackageDependencyParser $dependencyParser = new PackageDependencyParser(),
    ) {
    }

    /**
     * @param list<Message>|null $issues
     *
     * @return list<array{0: string, 1: string}>
     */
    public function dependencies(ExtensionPackage $package, ?array &$issues = null): array
    {
        $metadata = $package->metadata();
        $manifest = $metadata['manifest'] ?? [];
        $value = is_array($manifest)
            ? ($manifest['PACKAGE_DEPENDENCIES'] ?? null)
            : ($metadata['dependencies'] ?? null);

        $dependencies = $this->dependencyParser->parse($value);

        if (null !== $dependencies) {
            return $dependencies;
        }

        if (null !== $issues) {
            $issues[] = Message::create(
                PackageMessageCode::PACKAGE_DEPENDENCY_INVALID,
                PackageMessageKey::PACKAGE_DEPENDENCY_INVALID,
                ['%package%' => $package->packageName()],
                ['package' => $package->packageName(), 'value' => $value],
                MessageLevel::Error,
            );
        }

        return [];
    }
}
