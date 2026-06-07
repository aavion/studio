<?php

declare(strict_types=1);

namespace App\Core\Package\Api;

use App\Core\Package\PackageAdminOverview;

final readonly class PackageApiReadModel
{
    public function __construct(private PackageAdminOverview $overview)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function packages(): array
    {
        return array_map(
            static fn (array $package): array => [
                'type' => 'package',
                'id' => (string) $package['package_name'],
                'attributes' => [
                    'label' => $package['label'],
                    'label_key' => $package['label_key'],
                    'description' => $package['description'],
                    'description_key' => $package['description_key'],
                    'author' => $package['author'],
                    'path' => $package['path'],
                    'immutable' => $package['immutable'],
                    'status' => $package['status'],
                    'status_label_key' => $package['status_label_key'],
                    'status_tone' => $package['status_tone'],
                    'scopes' => $package['scopes'],
                    'manifest_version' => $package['manifest_version'],
                    'installed_version' => $package['installed_version'],
                    'available_version' => $package['available_version'],
                ],
            ],
            $this->overview->packages(),
        );
    }
}
