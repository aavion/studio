<?php

declare(strict_types=1);

namespace App\Core\Package\Event;

use App\Core\Event\PublicEventInterface;
use App\Core\Package\PackageAssetSyncPackage;
use Symfony\Contracts\EventDispatcher\Event;

final class PackageAssetSyncStartedEvent extends Event implements PublicEventInterface
{
    /**
     * @param list<PackageAssetSyncPackage> $packages
     */
    public function __construct(private readonly array $packages)
    {
    }

    /**
     * @return list<PackageAssetSyncPackage>
     */
    public function packages(): array
    {
        return $this->packages;
    }
}
