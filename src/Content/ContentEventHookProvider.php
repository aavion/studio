<?php

declare(strict_types=1);

namespace App\Content;

use App\Content\Event\ContentRenderContextEvent;
use App\Content\Event\ContentRenderedEvent;
use App\Core\Event\EventHookDescriptor;
use App\Core\Event\EventHookDescriptorProviderInterface;
use App\Core\Event\EventHookMode;
use App\Core\Event\EventMessageKey;

final readonly class ContentEventHookProvider implements EventHookDescriptorProviderInterface
{
    /**
     * @return iterable<EventHookDescriptor>
     */
    public function hooks(): iterable
    {
        yield new EventHookDescriptor(
            ContentRenderContextEvent::class,
            'content',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_CONTENT_RENDER_CONTEXT_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            ContentRenderedEvent::class,
            'content',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_CONTENT_RENDERED_SUMMARY,
            mutable: true,
        );
    }
}
