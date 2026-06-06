<?php

declare(strict_types=1);

namespace App\View;

use App\Core\Event\EventHookDescriptor;
use App\Core\Event\EventHookDescriptorProviderInterface;
use App\Core\Event\EventHookMode;
use App\Core\Event\EventMessageKey;
use App\View\Event\OutputGeneratedEvent;
use App\View\Event\ResponseHeadersEvent;

final readonly class ViewEventHookProvider implements EventHookDescriptorProviderInterface
{
    /**
     * @return iterable<EventHookDescriptor>
     */
    public function hooks(): iterable
    {
        yield new EventHookDescriptor(
            ViewContextEvent::class,
            'view',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_VIEW_CONTEXT_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            ResponseHeadersEvent::class,
            'http',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_RESPONSE_HEADERS_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            OutputGeneratedEvent::class,
            'view',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_OUTPUT_GENERATED_SUMMARY,
            mutable: true,
        );
    }
}
