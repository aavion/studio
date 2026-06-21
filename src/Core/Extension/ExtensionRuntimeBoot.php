<?php

declare(strict_types=1);

namespace App\Core\Extension;

use Closure;

final readonly class ExtensionRuntimeBoot
{
    private Closure $boot;

    public function __construct(callable $boot)
    {
        $this->boot = Closure::fromCallable($boot);
    }

    public function boot(ExtensionRuntimeContext $context): void
    {
        ($this->boot)($context);
    }
}
