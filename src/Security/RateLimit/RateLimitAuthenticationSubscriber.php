<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final readonly class RateLimitAuthenticationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RateLimitResetService $resets,
        private RateLimitEnforcer $enforcer,
        private RateLimitResponseRenderer $responses,
        private string $environment,
    ) {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            LoginFailureEvent::class => 'onLoginFailure',
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $this->resets->resetLoginAttempts($event->getRequest());
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->enabledForRequest($request->headers->get('X-Rate-Limit-Testing'))) {
            return;
        }

        $result = $this->enforcer->check($request, RateLimitEnforcementStage::AuthenticationFailure);
        if ($result->isAllowed()) {
            return;
        }

        $event->setResponse($this->responses->tooManyRequests($request, $result));
    }

    private function enabledForRequest(?string $testOptIn): bool
    {
        return 'test' !== $this->environment || '1' === $testOptIn;
    }
}
