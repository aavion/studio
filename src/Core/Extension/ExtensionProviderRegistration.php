<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Entity\Extension;
use Closure;

final readonly class ExtensionProviderRegistration
{
    public function __construct(
        private Extension $extension,
        private ExtensionProviderContribution $contribution,
    ) {
    }

    public function extension(): Extension
    {
        return $this->extension;
    }

    public function extensionName(): string
    {
        return $this->extension->extensionName();
    }

    public function scope(): ExtensionScope
    {
        return $this->contribution->scope();
    }

    public function provider(): Closure
    {
        return $this->contribution->provider();
    }
}
