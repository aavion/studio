<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Event\EventHookDescriptor;
use App\Entity\Extension;

final readonly class ExtensionEventContext
{
    public function __construct(
        private Extension $extension,
        private EventHookDescriptor $hook,
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

    public function path(): string
    {
        return $this->extension->path();
    }

    public function hook(): EventHookDescriptor
    {
        return $this->hook;
    }
}
