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

final readonly class MercureUiAlertPublisher implements UiAlertPublisherInterface
{
    public function __construct(
        private HubInterface $hub,
        private UiAlertTopicFactory $topicFactory,
        private UiAlertMessageFactory $alertFactory,
    ) {
    }

    public function publish(string $topic, UiAlert|Message|UiAlertTranslation $alert, ?string $locale = null, bool $private = false): ?string
    {
        $payload = $this->alertFactory->create($alert, $locale)->toArray();

        try {
            $data = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $id = $payload['id'] ?? null;

        return $this->hub->publish(new Update(
            $topic,
            $data,
            private: $private,
            id: is_string($id) ? $id : null,
            type: 'ui-alert',
        ));
    }

    public function publishToUser(UserAccount|UserInterface|string $user, UiAlert|Message|UiAlertTranslation $alert, ?string $locale = null): ?string
    {
        return $this->publish($this->topicFactory->userTopic($user), $alert, $locale);
    }

    public function publishToSession(SessionInterface|string $session, UiAlert|Message|UiAlertTranslation $alert, ?string $locale = null): ?string
    {
        return $this->publish($this->topicFactory->sessionTopic($session), $alert, $locale);
    }

}
