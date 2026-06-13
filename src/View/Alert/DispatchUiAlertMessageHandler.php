<?php

declare(strict_types=1);

namespace App\View\Alert;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final readonly class DispatchUiAlertMessageHandler
{
    public function __construct(private UiAlertDispatcherInterface $dispatcher)
    {
    }

    public function __invoke(DispatchUiAlertMessage $message): void
    {
        $text = trim((string) ($message->payload['message'] ?? ''));
        if ('' === $text) {
            return;
        }

        try {
            $alert = new UiAlert(
                $text,
                (string) ($message->payload['level'] ?? 'info'),
                (bool) ($message->payload['persistent'] ?? false),
                is_string($message->payload['code'] ?? null) ? $message->payload['code'] : null,
                is_string($message->payload['translation_key'] ?? null) ? $message->payload['translation_key'] : null,
                is_array($message->payload['context'] ?? null) ? $message->payload['context'] : [],
                (string) ($message->payload['mode'] ?? 'auto'),
                is_string($message->payload['id'] ?? null) ? $message->payload['id'] : null,
                is_array($message->payload['actions'] ?? null) ? $message->payload['actions'] : [],
                (bool) ($message->payload['loading'] ?? false),
                is_string($message->payload['title'] ?? null) ? $message->payload['title'] : null,
            );
        } catch (Throwable) {
            return;
        }

        $this->dispatcher->addAlertToTopic($message->topic, $alert, new UiAlertDeliveryOptions(
            $message->delivery,
            $message->private,
            $message->ttlSeconds,
            $message->locale,
        ));
    }
}
