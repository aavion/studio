<?php

declare(strict_types=1);

namespace App\Core\Extension;

final readonly class ExtensionAssetRebuildMessage
{
    public function __construct(
        private string $environment,
        private string $trigger = 'extension_lifecycle',
    ) {
    }

    public function environment(): string
    {
        $environment = trim($this->environment);

        return '' === $environment ? 'prod' : $environment;
    }

    public function trigger(): string
    {
        $trigger = trim($this->trigger);

        return '' === $trigger ? 'extension_lifecycle' : $trigger;
    }
}
