<?php

declare(strict_types=1);

namespace App\Core\Extension\Api;

use App\Api\Endpoint\ExtensionApiEndpointPath;
use App\Core\Extension\ExtensionAdminOverview;

final readonly class ExtensionApiReadModel
{
    public function __construct(private ExtensionAdminOverview $overview)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function extensions(): array
    {
        return array_map(
            fn (array $extension): array => [
                'type' => 'extension',
                'id' => $this->extensionSlug((string) $extension['extension_name']),
                'attributes' => [
                    'extension_name' => $extension['extension_name'],
                    'extension_slug' => $this->extensionSlug((string) $extension['extension_name']),
                    'label' => $extension['label'],
                    'label_key' => $extension['label_key'],
                    'description' => $extension['description'],
                    'description_key' => $extension['description_key'],
                    'author' => $extension['author'],
                    'path' => $extension['path'],
                    'immutable' => $extension['immutable'],
                    'status' => $extension['status'],
                    'status_label_key' => $extension['status_label_key'],
                    'status_tone' => $extension['status_tone'],
                    'scopes' => $extension['scopes'],
                    'manifest_version' => $extension['manifest_version'],
                    'installed_version' => $extension['installed_version'],
                    'available_version' => $extension['available_version'],
                ],
            ],
            $this->overview->extensions(),
        );
    }

    public function extensionNameForSlug(string $slug): ?string
    {
        foreach ($this->overview->extensions() as $extension) {
            $extensionName = (string) $extension['extension_name'];
            if ($extensionName === $slug) {
                return $extensionName;
            }
        }

        return null;
    }

    public function extensionSlug(string $extensionName): string
    {
        return ExtensionApiEndpointPath::slug($extensionName);
    }
}
