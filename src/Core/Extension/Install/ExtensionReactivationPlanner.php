<?php

declare(strict_types=1);

namespace App\Core\Extension\Install;

use App\Core\Extension\ExtensionStatus;

final readonly class ExtensionReactivationPlanner
{
    /**
     * @param list<string> $deactivationTargets
     * @param array<string, ExtensionStatus> $previousStatuses
     *
     * @return list<string>
     */
    public function order(string $slug, array $deactivationTargets, array $previousStatuses): array
    {
        $targets = [];

        foreach (array_reverse($deactivationTargets) as $extensionName) {
            if (
                $slug === $extensionName
                || ExtensionStatus::Active !== ($previousStatuses[$extensionName] ?? null)
            ) {
                continue;
            }

            $targets[$extensionName] = $extensionName;
        }

        return array_values($targets);
    }
}
