<?php

declare(strict_types=1);

namespace App\Core\Extension\Settings;

interface ExtensionSettingProviderInterface
{
    /**
     * @return list<ExtensionSettingDefinition>
     */
    public function extensionSettings(): array;
}
