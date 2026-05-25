<?php

declare(strict_types=1);

namespace App\Core\Package;

final readonly class PackageDiscoveryMessage
{
    public function __construct(private string $trigger = 'manual')
    {
    }

    public function trigger(): string
    {
        $trigger = trim($this->trigger);

        return '' === $trigger ? 'manual' : $trigger;
    }
}
