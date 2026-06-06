<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

use App\Core\Package\ExtensionPackageStatus;

final readonly class PackageReactivationPlanner
{
    /**
     * @param list<string> $deactivationTargets
     * @param array<string, ExtensionPackageStatus> $previousStatuses
     *
     * @return list<string>
     */
    public function order(string $slug, array $deactivationTargets, array $previousStatuses): array
    {
        $targets = [];

        foreach (array_reverse($deactivationTargets) as $packageName) {
            if (
                $slug === $packageName
                || ExtensionPackageStatus::Active !== ($previousStatuses[$packageName] ?? null)
            ) {
                continue;
            }

            $targets[$packageName] = $packageName;
        }

        return array_values($targets);
    }
}
