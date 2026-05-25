<?php

declare(strict_types=1);

namespace App\Core\Package;

final readonly class PackageAssetRebuildMessage
{
    public function __construct(
        private string $environment,
        private string $trigger = 'package_lifecycle',
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

        return '' === $trigger ? 'package_lifecycle' : $trigger;
    }
}
