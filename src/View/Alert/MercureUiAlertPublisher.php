<?php

declare(strict_types=1);

namespace App\View\Alert;

use App\Core\Message\Message;
use App\Entity\UserAccount;
use JsonException;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MercureUiAlertPublisher implements UiAlertPublisherInterface
{
    public function __construct(
        private HubInterface $hub,
        private UiAlertTopicFactory $topicFactory,
        private TranslatorInterface $translator,
    ) {
    }

    public function publish(string $topic, UiAlert|Message $alert, ?string $locale = null, bool $private = true): ?string
    {
        $payload = $alert instanceof Message
            ? $this->fromMessage($alert, $locale)->toArray()
            : $alert->toArray();

        try {
            $data = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return $this->hub->publish(new Update($topic, $data, private: $private, type: 'ui-alert'));
    }

    public function publishToUser(UserAccount|UserInterface|string $user, UiAlert|Message $alert, ?string $locale = null): ?string
    {
        return $this->publish($this->topicFactory->userTopic($user), $alert, $locale);
    }

    public function publishToSession(SessionInterface|string $session, UiAlert|Message $alert, ?string $locale = null): ?string
    {
        return $this->publish($this->topicFactory->sessionTopic($session), $alert, $locale);
    }

    private function fromMessage(Message $message, ?string $locale): UiAlert
    {
        return UiAlert::translated(
            $this->translator->trans($message->translationKey(), $message->parameters(), locale: $locale),
            $message->level(),
            $message->code(),
            $message->translationKey(),
            $message->context(),
        );
    }
}
