<?php

declare(strict_types=1);

namespace App\Core\Event;

use App\Debug\StudioDebugCollector;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\OperationIssue;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

final readonly class PublicEventDispatcher
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private PublicEventHookRegistry $hookRegistry,
        private ?StudioDebugCollector $debugCollector = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function __invoke(PublicEventInterface $event, array $context = [], ?string $package = null): PublicEventDispatchResult
    {
        return $this->dispatch($event, $context, $package);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function dispatch(PublicEventInterface $event, array $context = [], ?string $package = null): PublicEventDispatchResult
    {
        $eventClass = $event::class;

        try {
            $registeredHooks = $this->hookRegistry->byEventClass();
        } catch (Throwable $error) {
            $this->debugCollector?->recordHook($eventClass, 'unknown', 'unknown', false, 'registry_failed', $context, $package, 1);

            return PublicEventDispatchResult::failed($event, [
                OperationIssue::create(MessageCode::EVENT_HOOK_INVALID, MessageKey::EVENT_HOOK_INVALID, [
                    '%event%' => $eventClass,
                ], [
                    'event' => $eventClass,
                    'exception' => $error::class,
                    'message' => $error->getMessage(),
                ], MessageLevel::Error),
            ]);
        }

        if (!isset($registeredHooks[$eventClass])) {
            $this->debugCollector?->recordHook($eventClass, 'unknown', 'unknown', false, 'unregistered', $context, $package, 1);

            return PublicEventDispatchResult::failed($event, [
                OperationIssue::create(MessageCode::EVENT_HOOK_UNREGISTERED, MessageKey::EVENT_HOOK_UNREGISTERED, [
                    '%event%' => $eventClass,
                ], [
                    'event' => $eventClass,
                    'context' => $context,
                    'package' => $package,
                ], MessageLevel::Error),
            ]);
        }

        try {
            $this->eventDispatcher->dispatch($event);
        } catch (Throwable $error) {
            $this->debugCollector?->recordHook(
                $eventClass,
                $registeredHooks[$eventClass]->domain(),
                $registeredHooks[$eventClass]->mode()->value,
                $registeredHooks[$eventClass]->mutable(),
                'failed',
                $context,
                $package,
                1,
            );

            $issue = OperationIssue::create(MessageCode::EVENT_HOOK_LISTENER_FAILED, MessageKey::EVENT_HOOK_LISTENER_FAILED, [
                '%event%' => $eventClass,
            ], [
                'event' => $eventClass,
                'domain' => $registeredHooks[$eventClass]->domain(),
                'exception' => $error::class,
                'message' => $error->getMessage(),
                'context' => $context,
                'package' => $package,
            ], MessageLevel::Error);

            $this->reportHookFailure($event, $registeredHooks[$eventClass], $issue, $error, $context, $package);

            return PublicEventDispatchResult::failed($event, [$issue]);
        }

        $this->debugCollector?->recordHook(
            $eventClass,
            $registeredHooks[$eventClass]->domain(),
            $registeredHooks[$eventClass]->mode()->value,
            $registeredHooks[$eventClass]->mutable(),
            'success',
            $context,
            $package,
        );

        return PublicEventDispatchResult::success($event);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function reportHookFailure(
        PublicEventInterface $event,
        EventHookDescriptor $hook,
        OperationIssue $issue,
        Throwable $exception,
        array $context,
        ?string $package,
    ): void {
        try {
            $this->eventDispatcher->dispatch(new PublicHookFailedEvent(
                $event,
                $hook,
                $issue,
                $exception,
                $context,
                $package,
            ));
        } catch (Throwable) {
            // Failure reporting must never hide the original hook failure.
        }
    }
}
