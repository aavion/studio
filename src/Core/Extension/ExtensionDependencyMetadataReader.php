<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Entity\Extension;

final readonly class ExtensionDependencyMetadataReader
{
    public function __construct(
        private ExtensionDependencyParser $dependencyParser = new ExtensionDependencyParser(),
    ) {
    }

    /**
     * @param list<Message>|null $issues
     *
     * @return list<array{0: string, 1: string}>
     */
    public function dependencies(Extension $extension, ?array &$issues = null): array
    {
        $metadata = $extension->metadata();
        $manifest = $metadata['manifest'] ?? [];
        $value = is_array($manifest)
            ? ($manifest['EXTENSION_DEPENDENCIES'] ?? null)
            : ($metadata['dependencies'] ?? null);

        $dependencies = $this->dependencyParser->parse($value);

        if (null !== $dependencies) {
            return $dependencies;
        }

        if (null !== $issues) {
            $issues[] = Message::create(
                ExtensionMessageCode::EXTENSION_DEPENDENCY_INVALID,
                ExtensionMessageKey::EXTENSION_DEPENDENCY_INVALID,
                ['%extension%' => $extension->extensionName()],
                ['extension' => $extension->extensionName(), 'value' => $value],
                MessageLevel::Error,
            );
        }

        return [];
    }
}
