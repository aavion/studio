<?php

declare(strict_types=1);

namespace App\Core\Event;

final class EventMessageKey
{
    public const EVENT_HOOK_INVALID = 'message.event.hook.invalid';
    public const EVENT_HOOK_UNREGISTERED = 'message.event.hook.unregistered';
    public const EVENT_HOOK_LISTENER_FAILED = 'message.event.hook.listener_failed';
    public const EVENT_HOOK_VIEW_CONTEXT_SUMMARY = 'message.event.hook.view_context.summary';
    public const EVENT_HOOK_CONTENT_RENDER_CONTEXT_SUMMARY = 'message.event.hook.content_render_context.summary';
    public const EVENT_HOOK_CONTENT_RENDERED_SUMMARY = 'message.event.hook.content_rendered.summary';
    public const EVENT_HOOK_NAVIGATION_BUILDER_SUMMARY = 'message.event.hook.navigation_builder.summary';
    public const EVENT_HOOK_STATIC_VIEW_INJECTION_REGISTRY_SUMMARY = 'message.event.hook.static_view_injection_registry.summary';
    public const EVENT_HOOK_DYNAMIC_VIEW_INJECTION_REGISTRY_SUMMARY = 'message.event.hook.dynamic_view_injection_registry.summary';
    public const EVENT_HOOK_RESPONSE_HEADERS_SUMMARY = 'message.event.hook.response_headers.summary';
    public const EVENT_HOOK_OUTPUT_GENERATED_SUMMARY = 'message.event.hook.output_generated.summary';
    public const EVENT_HOOK_PACKAGE_ASSET_SYNC_STARTED_SUMMARY = 'message.event.hook.package_asset_sync_started.summary';
    public const EVENT_HOOK_PACKAGE_ASSET_REGISTRY_BUILD_SUMMARY = 'message.event.hook.package_asset_registry_build.summary';
    public const EVENT_HOOK_PACKAGE_ASSET_SYNC_COMPLETED_SUMMARY = 'message.event.hook.package_asset_sync_completed.summary';
}
