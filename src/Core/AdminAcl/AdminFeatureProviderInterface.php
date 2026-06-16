<?php

declare(strict_types=1);

namespace App\Core\AdminAcl;

interface AdminFeatureProviderInterface
{
    /**
     * @return list<AdminFeatureDefinition>
     */
    public function adminFeatures(): array;
}
