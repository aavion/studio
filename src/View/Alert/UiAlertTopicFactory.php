<?php

declare(strict_types=1);

namespace App\View\Alert;

use App\Entity\UserAccount;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class UiAlertTopicFactory
{
    public const PREFIX = 'urn:system:ui-alerts:';

    public function __construct(
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
            $sessionId = $this->sessionId($request, $session);
            if (null !== $sessionId) {
                $topics[] = $this->sessionTopic($sessionId);
            }
        }

        return array_values(array_unique($topics));
    }

    public function isUiAlertTopic(string $topic): bool
    {
        $matches = preg_match('/^'.preg_quote(self::PREFIX, '/').'(user|session):[a-f0-9]{64}$/', $topic);

        return 1 === $matches;
    }

    private function topic(string $scope, string $identity): string
    {
        return self::PREFIX.$scope.':'.$this->hash($scope, $identity);
    }

    private function hash(string $scope, string $identity): string
    {
        return hash_hmac('sha256', $scope.':'.$identity, $this->secret);
    }

    private function sessionId(Request $request, SessionInterface $session): ?string
    {
        if ($session->isStarted()) {
            return $session->getId();
        }

        $cookieValue = $request->cookies->get($session->getName());
        if (!is_string($cookieValue)) {
            return null;
        }

        $sessionId = trim($cookieValue);

        return '' !== $sessionId ? $sessionId : null;
    }
}
