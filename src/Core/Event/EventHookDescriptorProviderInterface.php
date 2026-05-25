<?php

declare(strict_types=1);

namespace App\Core\Event;

interface EventHookDescriptorProviderInterface
{
    /**
     * @return iterable<EventHookDescriptor>
     */
    public function hooks(): iterable;
}
