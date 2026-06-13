<?php

declare(strict_types=1);

namespace App\View\Alert;

use App\Core\Message\Message;
use App\Core\Id\UuidFactory;
use App\Entity\UserAccount;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

final readonly class UiAlertDispatcher implements UiAlertDispatcherInterface
{
    public function __construct(
        private UiAlertTopicFactory $topicFactory,
        private UiAlertMessageFactory $alertFactory,
        private UiAlertInbox $inbox,
        private UiAlertPublisherInterface $publisher,
        private MercureAvailability $mercureAvailability,
        private RequestUiAlertFlasher $flasher,
        private RequestStack $requestStack,
        private Security $security,
        private UuidFactory $uuidFactory = new UuidFactory(),
    ) {
    }

    public function addAlert(
        UiAlert|Message|UiAlertTranslation $alert,
        UiAlertDelivery|UiAlertDeliveryOptions $delivery = UiAlertDelivery::Direct,
        ?UiAlertPresentation $presentation = null,
    ): bool
    {
        $options = $this->options($delivery);
        $uiAlert = $this->alertFactory->create($alert, $options->locale(), $presentation);

        if ($options->flashes()) {
            return $this->flasher->flash($uiAlert);
        }

        $uiAlert = $this->ensureAlertId($uiAlert);
        $topics = $this->currentTopics();
        if ([] === $topics) {
            return $options->queues() ? $this->flasher->flash($uiAlert) : false;
        }

        $queued = $options->queues() && null !== $this->inbox->append($topics, $uiAlert, $options->ttlSeconds());
        $pushed = $options->pushes() && $this->pushTopics($topics, $uiAlert, $options);

        return $queued || $pushed;
    }

    public function addAlertToTopic(
        string $topic,
        UiAlert|Message|UiAlertTranslation $alert,
        UiAlertDelivery|UiAlertDeliveryOptions $delivery = UiAlertDelivery::Queue,
        ?UiAlertPresentation $presentation = null,
    ): bool
    {
        $options = $this->options($delivery);
        $uiAlert = $this->alertFactory->create($alert, $options->locale(), $presentation);
        if (!$options->flashes()) {
            $uiAlert = $this->ensureAlertId($uiAlert);
        }
        $queued = false;
        $pushed = false;
        $flashed = false;

        if ($options->queues()) {
            $queued = null !== $this->inbox->append([$topic], $uiAlert, $options->ttlSeconds());
        } elseif ($options->flashes()) {
            $flashed = $this->flasher->flash($uiAlert);
        }

        if ($options->pushes()) {
            try {
                $pushed = null !== $this->publisher->publish($topic, $uiAlert, $options->locale(), $options->private());
            } catch (Throwable) {
                $pushed = false;
            }
        }

        return $queued || $pushed || $flashed;
    }

    public function addAlertToUser(
        UserAccount|UserInterface|string $user,
        UiAlert|Message|UiAlertTranslation $alert,
        UiAlertDelivery|UiAlertDeliveryOptions $delivery = UiAlertDelivery::Queue,
        ?UiAlertPresentation $presentation = null,
    ): bool
    {
        return $this->addAlertToTopic($this->topicFactory->userTopic($user), $alert, $delivery, $presentation);
    }

    public function addAlertToSession(
        SessionInterface|string $session,
        UiAlert|Message|UiAlertTranslation $alert,
        UiAlertDelivery|UiAlertDeliveryOptions $delivery = UiAlertDelivery::Queue,
        ?UiAlertPresentation $presentation = null,
    ): bool
    {
        return $this->addAlertToTopic($this->topicFactory->sessionTopic($session), $alert, $delivery, $presentation);
    }

    private function options(UiAlertDelivery|UiAlertDeliveryOptions $delivery): UiAlertDeliveryOptions
    {
        return $delivery instanceof UiAlertDeliveryOptions ? $delivery : $delivery->toOptions();
    }

    private function ensureAlertId(UiAlert $alert): UiAlert
    {
        return $alert->hasId() ? $alert : $alert->withId('ui-alert-'.$this->uuidFactory->generate());
    }

    /**
     * @return list<string>
     */
    private function currentTopics(): array
    {
        $user = $this->security->getUser();

        return $this->topicFactory->topicsFor(
            $this->requestStack->getMainRequest(),
            $user instanceof UserInterface ? $user : null,
        );
    }

    /**
     * @param list<string> $topics
     */
    private function pushTopics(array $topics, UiAlert $alert, UiAlertDeliveryOptions $options): bool
    {
        if (!$this->mercureAvailability->available()) {
            return false;
        }

        $pushed = false;

        foreach ($topics as $topic) {
            try {
                $pushed = null !== $this->publisher->publish($topic, $alert, $options->locale(), $options->private()) || $pushed;
            } catch (Throwable) {
                continue;
            }
        }

        return $pushed;
    }
}
