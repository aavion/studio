<?php

declare(strict_types=1);

namespace App\Core\Extension\Event;

use App\Core\Event\PublicEventInterface;
use App\Core\Extension\ExtensionAssetSyncTarget;
use Symfony\Contracts\EventDispatcher\Event;

final class ExtensionAssetSyncCompletedEvent extends Event implements PublicEventInterface
{
    /**
     * @param list<ExtensionAssetSyncTarget> $extensions
     * @param array{extensions: int, mirrored_assets: int, css_entries: int, javascript_entries: int, tailwind_sources: int} $metrics
     */
    public function __construct(
        private readonly array $extensions,
        private readonly array $metrics,
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
     * @return array{extensions: int, mirrored_assets: int, css_entries: int, javascript_entries: int, tailwind_sources: int}
     */
    public function metrics(): array
    {
        return $this->metrics;
    }
}
