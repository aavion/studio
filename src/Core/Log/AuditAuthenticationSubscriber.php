<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Access\AccessActor;
use App\Entity\UserAccount;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Throwable;

final readonly class AuditAuthenticationSubscriber implements EventSubscriberInterface
{
    public function __construct(private AuditLoggerInterface $auditLogger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $this->safeLog($this->actorFromUser($event->getUser()), 'auth.login_success', [
            'firewall' => $event->getFirewallName(),
        ]);
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $this->safeLog(AccessActor::anonymous(), 'auth.login_failed', [
            'firewall' => $event->getFirewallName(),
            'reason' => $event->getException()::class,
        ]);
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        $this->safeLog($this->actorFromUser($user), 'auth.logout');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeLog(AccessActor $actor, string $action, array $context = []): void
    {
        try {
            $this->auditLogger->log($actor, $action, $context);
        } catch (Throwable) {
            return;
        }
    }

    private function actorFromUser(mixed $user): AccessActor
    {
        if ($user instanceof UserAccount) {
            return AccessActor::fromUserAccount($user);
        }

        if ($user instanceof UserInterface) {
            return AccessActor::fromAccess(0, username: $user->getUserIdentifier());
        }

        return AccessActor::anonymous();
    }
}
