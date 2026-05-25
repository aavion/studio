<?php

declare(strict_types=1);

namespace App\Core\Package\Event;

use App\Core\Event\PublicEventInterface;
use App\Core\Package\PackageAssetContribution;
use App\Core\Package\PackageAssetSyncPackage;
use Symfony\Contracts\EventDispatcher\Event;

final class PackageAssetRegistryBuildEvent extends Event implements PublicEventInterface
{
    /**
     * @param list<PackageAssetSyncPackage> $packages
     * @param list<PackageAssetContribution> $contributions
     */
    public function __construct(
        private readonly array $packages,
        private array $contributions,
    ) {
    }

    /**
     * @return list<PackageAssetSyncPackage>
     */
    public function packages(): array
    {
        return $this->packages;
    }

    /**
     * @return list<PackageAssetContribution>
     */
    public function contributions(): array
    {
        return $this->contributions;
    }

    public function addContribution(PackageAssetContribution $contribution): void
    {
        $this->contributions[] = $contribution;
    }
}
