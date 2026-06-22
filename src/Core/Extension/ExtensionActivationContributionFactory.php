<?php

declare(strict_types=1);

namespace App\Core\Extension;

use Closure;

final readonly class ExtensionActivationContributionFactory
{
    private Closure $factory;

    public function __construct(callable $factory)
    {
        $this->factory = Closure::fromCallable($factory);
    }

    public function contributions(ExtensionContributionContext $context): mixed
    {
        return ($this->factory)($context);
    }
}
