<?php

declare(strict_types=1);

namespace App\Core\Extension;

final readonly class ActiveExtensionAssetProvider implements ActiveExtensionAssetProviderInterface
{
    public function __construct(private ActiveExtensionProviderInterface $activeExtensionProvider)
    {
    }

    /**
     * @return list<ExtensionAssetSyncTarget>
     */
    public function extensions(): array
    {
        $extensions = [];

        foreach ($this->activeExtensionProvider->extensions() as $extension) {
            $extensions[] = new ExtensionAssetSyncTarget(
                $extension->extensionName(),
                $extension->path(),
                $extension->scopes(),
            );
        }

        return $extensions;
    }
}
