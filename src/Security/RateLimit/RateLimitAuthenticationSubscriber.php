<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use App\Core\Log\AccessRequestMetadata;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseSubject;
use App\Security\Abuse\AbuseSubjectType;
use App\Security\Abuse\SecuritySignalRecorder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Throwable;

final readonly class RateLimitAuthenticationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RateLimitResetService $resets,
        private RateLimitEnforcer $enforcer,
        private RateLimitResponseRenderer $responses,
        private string $environment,
        private ?SecuritySignalRecorder $signalRecorder = null,
        private ?AbuseRequestInspector $inspector = null,
        private ?AccessRequestMetadata $accessRequestMetadata = null,
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
        $this->recordAuthFailure($event);

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

    private function recordAuthFailure(LoginFailureEvent $event): void
    {
        if (null === $this->signalRecorder || null === $this->inspector || null === $this->accessRequestMetadata) {
            return;
        }

        try {
            $request = $event->getRequest();
            $inspection = $this->inspector->inspect($request);
            $profile = $inspection['profile'];
            $subjects = $inspection['subjects'];
            $visitor = $subjects->first(AbuseSubjectType::Visitor);
            $ipBucket = $subjects->first(AbuseSubjectType::IpBucket);

            foreach (array_filter([$visitor, $ipBucket]) as $subject) {
                if (!$subject instanceof AbuseSubject) {
                    continue;
                }

                $this->signalRecorder->record(
                    'auth',
                    'security.signal.auth_failure',
                    $subject->type()->value,
                    $subject->identifier(),
                    ipDerived: $subject->ipDerived(),
                    severity: 'NOTICE',
                    confidence: 70,
                    requestFamily: $profile->family()->value,
                    requestIntent: $profile->intent()->value,
                    requestId: $this->accessRequestMetadata->requestId($request),
                    visitorId: $visitor?->identifier() ?? 'n/a',
                    path: $this->accessRequestMetadata->sanitizedPath($request),
                    route: $profile->route(),
                    httpStatus: null,
                    context: [
                        'ip_bucket' => $ipBucket?->identifier(),
                        'authenticator' => $event->getFirewallName(),
                    ],
                );
            }
        } catch (Throwable) {
            return;
        }
    }
}
