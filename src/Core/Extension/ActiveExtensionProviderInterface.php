<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Entity\Extension;

interface ActiveExtensionProviderInterface
{
    /**
     * @return list<Extension>
     */
    public function extensions(?ExtensionScope $scope = null): array;

    public function extension(string $extensionName): ?Extension;
}
