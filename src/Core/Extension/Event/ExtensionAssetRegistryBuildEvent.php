<?php

declare(strict_types=1);

namespace App\Core\Extension\Event;

use App\Core\Event\PublicEventInterface;
use App\Core\Extension\ExtensionAssetContribution;
use App\Core\Extension\ExtensionAssetSyncTarget;
use Symfony\Contracts\EventDispatcher\Event;

final class ExtensionAssetRegistryBuildEvent extends Event implements PublicEventInterface
{
    /**
     * @param list<ExtensionAssetSyncTarget> $extensions
     * @param list<ExtensionAssetContribution> $contributions
     */
    public function __construct(
        private readonly array $extensions,
        private array $contributions,
    ) {
    }

    /**
     * @return list<ExtensionAssetSyncTarget>
     */
    public function extensions(): array
    {
        return $this->extensions;
    }

    /**
     * @return list<ExtensionAssetContribution>
     */
    public function contributions(): array
    {
        return $this->contributions;
    }

    public function addContribution(ExtensionAssetContribution $contribution): void
    {
        $this->contributions[] = $contribution;
    }
}
