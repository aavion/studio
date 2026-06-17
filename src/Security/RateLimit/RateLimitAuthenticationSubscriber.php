<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final readonly class RateLimitAuthenticationSubscriber implements EventSubscriberInterface
{
    public function __construct(private RateLimitResetService $resets)
    {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $this->resets->resetLoginAttempts($event->getRequest());
    }
}
