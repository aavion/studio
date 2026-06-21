<?php

declare(strict_types=1);

namespace App\Core\Extension\Event;

use App\Core\Event\PublicEventInterface;
use App\Core\Extension\ExtensionAssetSyncTarget;
use Symfony\Contracts\EventDispatcher\Event;

final class ExtensionAssetSyncStartedEvent extends Event implements PublicEventInterface
{
    /**
     * @param list<ExtensionAssetSyncTarget> $extensions
     */
    public function __construct(private readonly array $extensions)
    {
    }

    /**
     * @return list<ExtensionAssetSyncTarget>
     */
    public function extensions(): array
    {
        return $this->extensions;
    }
}
