<?php

declare(strict_types=1);

namespace App\Core\Extension\Settings;

use App\Core\Extension\ActiveExtensionProviderInterface;
use App\Entity\Extension;
use Throwable;

final readonly class ExtensionSettingRegistry
{
    /**
     * @param iterable<ExtensionSettingProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
        private ActiveExtensionProviderInterface $activeExtensionProvider,
    ) {
    }

    /**
     * @return list<ExtensionSettingDefinition>
     */
    public function definitions(?string $extensionName = null, bool $activeOnly = true): array
    {
        $activeExtensions = $activeOnly ? $this->activeExtensionNames() : null;
        $definitions = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->extensionSettings() as $definition) {
                if (null !== $extensionName && $definition->extensionName() !== $extensionName) {
                    continue;
                }

                if (null !== $activeExtensions && !isset($activeExtensions[$definition->extensionName()])) {
                    continue;
                }

                $definitions[] = $definition;
            }
        }

        usort(
            $definitions,
            static fn (ExtensionSettingDefinition $left, ExtensionSettingDefinition $right): int => [
                $left->extensionName(),
                $left->sortOrder(),
                $left->label(),
                $left->key(),
            ] <=> [
                $right->extensionName(),
                $right->sortOrder(),
                $right->label(),
                $right->key(),
            ],
        );

        return $definitions;
    }

    /**
     * @return array<string, array{label: string, description: string|null, path: string}>
     */
    public function extensionsWithDefinitions(): array
    {
        $extensions = $this->activeExtensionMetadata();
        $definitions = $this->definitions();
        $result = [];

        foreach ($definitions as $definition) {
            $extensionName = $definition->extensionName();
            $result[$extensionName] = [
                'label' => $extensions[$extensionName]['label'] ?? $extensionName,
                'description' => $extensions[$extensionName]['description'] ?? null,
                'path' => '/admin/settings/extensions/'.$extensionName,
            ];
        }

        ksort($result);

        return $result;
    }

    /**
     * @return array<string, true>
     */
    private function activeExtensionNames(): array
    {
        return array_fill_keys(array_keys($this->activeExtensionMetadata()), true);
    }

    /**
     * @return array<string, array{label: string, description: string|null}>
     */
    private function activeExtensionMetadata(): array
    {
        try {
            $extensions = $this->activeExtensionProvider->extensions();
        } catch (Throwable) {
            return [];
        }

        $metadata = [];

        foreach ($extensions as $extension) {
            if (!$extension instanceof Extension) {
                continue;
            }

            $extensionMetadata = $extension->metadata();
            $label = $extensionMetadata['display_name'] ?? $extension->extensionName();
            $description = $extensionMetadata['description'] ?? null;

            $metadata[$extension->extensionName()] = [
                'label' => is_string($label) && '' !== trim($label) ? $label : $extension->extensionName(),
                'description' => is_string($description) && '' !== trim($description) ? $description : null,
            ];
        }

        return $metadata;
    }
}
