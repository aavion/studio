<?php

declare(strict_types=1);

namespace App\Core\Package\Settings;

interface PackageSettingProviderInterface
{
    /**
     * @return list<PackageSettingDefinition>
     */
    public function packageSettings(): array;
}
