<?php

declare(strict_types=1);

namespace App\View\Alert;

use App\Core\Message\Message;
use App\Entity\UserAccount;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\User\UserInterface;

interface UiAlertDispatcherInterface
{
    public function addAlert(
        UiAlert|Message|UiAlertTranslation $alert,
        UiAlertDelivery|UiAlertDeliveryOptions $delivery = UiAlertDelivery::Direct,
        ?UiAlertPresentation $presentation = null,
    ): bool;

    public function addAlertToTopic(
        string $topic,
        UiAlert|Message|UiAlertTranslation $alert,
        UiAlertDelivery|UiAlertDeliveryOptions $delivery = UiAlertDelivery::Queue,
        ?UiAlertPresentation $presentation = null,
    ): bool;

    public function addAlertToUser(
        UserAccount|UserInterface|string $user,
        UiAlert|Message|UiAlertTranslation $alert,
        UiAlertDelivery|UiAlertDeliveryOptions $delivery = UiAlertDelivery::Queue,
        ?UiAlertPresentation $presentation = null,
    ): bool;

    public function addAlertToSession(
        SessionInterface|string $session,
        UiAlert|Message|UiAlertTranslation $alert,
        UiAlertDelivery|UiAlertDeliveryOptions $delivery = UiAlertDelivery::Queue,
        ?UiAlertPresentation $presentation = null,
    ): bool;
}
