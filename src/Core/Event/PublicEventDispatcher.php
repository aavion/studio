<?php

declare(strict_types=1);

namespace App\Core\Event;

use App\Core\Event\EventMessageCode;
use App\Core\Event\EventMessageKey;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Workflow\WorkflowResult;
use App\Debug\StudioDebugCollector;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

final readonly class PublicEventDispatcher
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private PublicEventHookRegistry $hookRegistry,
        private WorkflowResultMessageReporterInterface $messageReporter,
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

            $issues = [
                Message::exception(EventMessageCode::EVENT_HOOK_INVALID, EventMessageKey::EVENT_HOOK_INVALID, [
                    '%event%' => $eventClass,
                ], [
                    'event' => $eventClass,
                    'exception' => $error::class,
                    'message' => $error->getMessage(),
                ]),
            ];

            $this->reportFailure($issues, $eventClass, $context, $package);

            return PublicEventDispatchResult::failed($event, $issues);
        }

        if (!isset($registeredHooks[$eventClass])) {
            $this->debugCollector?->recordHook($eventClass, 'unknown', 'unknown', false, 'unregistered', $context, $package, 1);

            $issues = [
                Message::create(EventMessageCode::EVENT_HOOK_UNREGISTERED, EventMessageKey::EVENT_HOOK_UNREGISTERED, [
                    '%event%' => $eventClass,
                ], [
                    'event' => $eventClass,
                    'context' => $context,
                    'package' => $package,
                ], MessageLevel::Error),
            ];

            $this->reportFailure($issues, $eventClass, $context, $package);

            return PublicEventDispatchResult::failed($event, $issues);
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

            $issue = Message::exception(EventMessageCode::EVENT_HOOK_LISTENER_FAILED, EventMessageKey::EVENT_HOOK_LISTENER_FAILED, [
                '%event%' => $eventClass,
            ], [
                'event' => $eventClass,
                'domain' => $registeredHooks[$eventClass]->domain(),
                'exception' => $error::class,
                'message' => $error->getMessage(),
                'context' => $context,
                'package' => $package,
            ]);

            $this->reportHookFailure($event, $registeredHooks[$eventClass], $issue, $error, $context, $package);
            $this->reportFailure([$issue], $eventClass, $context, $package);

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
        Message $issue,
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

    /**
     * @param list<Message> $issues
     * @param array<string, mixed> $context
     */
    private function reportFailure(array $issues, string $eventClass, array $context, ?string $package): void
    {
        $this->messageReporter->report(WorkflowResult::failed($issues), [
            'operation' => 'event.dispatch',
            'event' => $eventClass,
            'context' => $context,
            'package' => $package,
        ]);
    }
}
