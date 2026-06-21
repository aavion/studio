<?php

declare(strict_types=1);

namespace App\Core\Extension;

interface ActiveExtensionAssetProviderInterface
{
    /**
     * @return list<ExtensionAssetSyncTarget>
     */
    public function extensions(): array;
}
