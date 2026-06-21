<?php

declare(strict_types=1);

namespace App\Core\Extension;

final readonly class ExtensionDiscoveryMessage
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
