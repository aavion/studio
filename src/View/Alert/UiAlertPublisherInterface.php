<?php

declare(strict_types=1);

namespace App\View\Alert;

use App\Core\Message\Message;
use App\Entity\UserAccount;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\User\UserInterface;

interface UiAlertPublisherInterface
{
    public function publish(string $topic, UiAlert|Message $alert, ?string $locale = null, bool $private = true): ?string;

    public function publishToUser(UserAccount|UserInterface|string $user, UiAlert|Message $alert, ?string $locale = null): ?string;

    public function publishToSession(SessionInterface|string $session, UiAlert|Message $alert, ?string $locale = null): ?string;
}
