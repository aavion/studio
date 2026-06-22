<?php

declare(strict_types=1);

namespace App\Core\Extension;

use Closure;

final readonly class ExtensionProviderContribution
{
    private Closure $provider;

    public function __construct(
        private ExtensionScope $scope,
        callable $provider,
    ) {
        $this->provider = Closure::fromCallable($provider);
    }

    public function scope(): ExtensionScope
    {
        return $this->scope;
    }

    public function provider(): Closure
    {
        return $this->provider;
    }
}
