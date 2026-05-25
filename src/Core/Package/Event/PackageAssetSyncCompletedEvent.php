<?php

declare(strict_types=1);

namespace App\Core\Package\Event;

use App\Core\Event\PublicEventInterface;
use App\Core\Package\PackageAssetSyncPackage;
use Symfony\Contracts\EventDispatcher\Event;

final class PackageAssetSyncCompletedEvent extends Event implements PublicEventInterface
{
    /**
     * @param list<PackageAssetSyncPackage> $packages
     * @param array{packages: int, mirrored_assets: int, css_entries: int, javascript_entries: int, tailwind_sources: int} $metrics
     */
    public function __construct(
        private readonly array $packages,
        private readonly array $metrics,
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
     * @return array{packages: int, mirrored_assets: int, css_entries: int, javascript_entries: int, tailwind_sources: int}
     */
    public function metrics(): array
    {
        return $this->metrics;
    }
}
