<?php

declare(strict_types=1);

namespace App\View\Alert;

use App\Entity\UserAccount;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class UiAlertTopicFactory
{
    public function __construct(
        private string $defaultUri,
        private string $secret,
    ) {
    }

    public function userTopic(UserAccount|UserInterface|string $user): string
    {
        $identity = $user instanceof UserAccount
            ? $user->uid()
            : ($user instanceof UserInterface ? $user->getUserIdentifier() : $user);

        return $this->topic('user', $identity);
    }

    public function sessionTopic(SessionInterface|string $session): string
    {
        $sessionId = $session instanceof SessionInterface ? $session->getId() : $session;

        return $this->topic('session', $sessionId);
    }

    /**
     * @return list<string>
     */
    public function topicsFor(?Request $request, ?UserInterface $user): array
    {
        $topics = [];

        if ($user instanceof UserAccount) {
            $topics[] = $this->userTopic($user);
        }

        if (null !== $request && $request->hasSession()) {
            $session = $request->getSession();
            if ($session->isStarted()) {
                $topics[] = $this->sessionTopic($session);
            }
        }

        return array_values(array_unique($topics));
    }

    private function topic(string $scope, string $identity): string
    {
        return rtrim($this->baseUri(), '/').'/ui-alerts/'.$scope.'/'.$this->hash($scope, $identity);
    }

    private function baseUri(): string
    {
        $uri = rtrim($this->defaultUri, '/');

        return '' !== $uri ? $uri : 'https://localhost';
    }

    private function hash(string $scope, string $identity): string
    {
        return hash_hmac('sha256', $scope.':'.$identity, $this->secret);
    }
}
