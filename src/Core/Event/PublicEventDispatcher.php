<?php

declare(strict_types=1);

namespace App\Core\Event;

use App\Core\Event\EventMessageCode;
use App\Core\Event\EventMessageKey;
use App\Core\Extension\ExtensionEventListenerDispatcher;
use App\Core\Extension\ExtensionEventListenerFailedException;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Workflow\WorkflowResult;
use App\Debug\SystemDebugCollector;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

final readonly class PublicEventDispatcher
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private PublicEventHookRegistry $hookRegistry,
        private WorkflowResultMessageReporterInterface $messageReporter,
        private ?SystemDebugCollector $debugCollector = null,
        private ?ExtensionEventListenerDispatcher $extensionListenerDispatcher = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function __invoke(PublicEventInterface $event, array $context = [], ?string $extension = null): PublicEventDispatchResult
    {
        return $this->dispatch($event, $context, $extension);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function dispatch(PublicEventInterface $event, array $context = [], ?string $extension = null): PublicEventDispatchResult
    {
        $eventClass = $event::class;

        try {
            $registeredHooks = $this->hookRegistry->byEventClass();
        } catch (Throwable $error) {
            $this->debugCollector?->recordHook($eventClass, 'unknown', 'unknown', false, 'registry_failed', $context, $extension, 1);

            $issues = [
                Message::exception(EventMessageCode::EVENT_HOOK_INVALID, EventMessageKey::EVENT_HOOK_INVALID, [
                    '%event%' => $eventClass,
                ], [
                    'event' => $eventClass,
                    'exception' => $error::class,
                    'message' => $error->getMessage(),
                ]),
            ];

            $this->reportFailure($issues, $eventClass, $context, $extension);

            return PublicEventDispatchResult::failed($event, $issues);
        }

        if (!isset($registeredHooks[$eventClass])) {
            $this->debugCollector?->recordHook($eventClass, 'unknown', 'unknown', false, 'unregistered', $context, $extension, 1);

            $issues = [
                Message::create(EventMessageCode::EVENT_HOOK_UNREGISTERED, EventMessageKey::EVENT_HOOK_UNREGISTERED, [
                    '%event%' => $eventClass,
                ], [
                    'event' => $eventClass,
                    'context' => $context,
                    'extension' => $extension,
                ], MessageLevel::Error),
            ];

            $this->reportFailure($issues, $eventClass, $context, $extension);

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
                $extension,
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
                'extension' => $extension,
            ]);

            $this->reportHookFailure($event, $registeredHooks[$eventClass], $issue, $error, $context, $extension);
            $this->reportFailure([$issue], $eventClass, $context, $extension);

            return PublicEventDispatchResult::failed($event, [$issue]);
        }

        try {
            $this->extensionListenerDispatcher?->dispatch($event, $registeredHooks[$eventClass]);
        } catch (ExtensionEventListenerFailedException $error) {
            $extension = $error->registration()->extensionName();
            $previous = $error->getPrevious() ?? $error;
            $this->debugCollector?->recordHook(
                $eventClass,
                $registeredHooks[$eventClass]->domain(),
                $registeredHooks[$eventClass]->mode()->value,
                $registeredHooks[$eventClass]->mutable(),
                'failed',
                $context,
                $extension,
                1,
            );

            $issue = Message::exception(EventMessageCode::EVENT_HOOK_LISTENER_FAILED, EventMessageKey::EVENT_HOOK_LISTENER_FAILED, [
                '%event%' => $eventClass,
            ], [
                'event' => $eventClass,
                'domain' => $registeredHooks[$eventClass]->domain(),
                'exception' => $previous::class,
                'message' => $previous->getMessage(),
                'context' => $context,
                'extension' => $extension,
                'listener' => 'extension',
            ]);

            $this->reportHookFailure($event, $registeredHooks[$eventClass], $issue, $previous, [
                ...$context,
                'extension_listener' => $extension,
            ], null);
            $this->reportFailure([$issue], $eventClass, $context, $extension);

            return PublicEventDispatchResult::failed($event, [$issue]);
        }

        $this->debugCollector?->recordHook(
            $eventClass,
            $registeredHooks[$eventClass]->domain(),
            $registeredHooks[$eventClass]->mode()->value,
            $registeredHooks[$eventClass]->mutable(),
            'success',
            $context,
            $extension,
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
        ?string $extension,
    ): void {
        try {
            $this->eventDispatcher->dispatch(new PublicHookFailedEvent(
                $event,
                $hook,
                $issue,
                $exception,
                $context,
                $extension,
            ));
        } catch (Throwable) {
            // Failure reporting must never hide the original hook failure.
        }
    }

    /**
     * @param list<Message> $issues
     * @param array<string, mixed> $context
     */
    private function reportFailure(array $issues, string $eventClass, array $context, ?string $extension): void
    {
        $this->messageReporter->report(WorkflowResult::failed($issues), [
            'operation' => 'event.dispatch',
            'event' => $eventClass,
            'context' => $context,
            'extension' => $extension,
        ]);
    }
}
