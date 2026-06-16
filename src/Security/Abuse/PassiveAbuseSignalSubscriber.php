<?php

declare(strict_types=1);

namespace App\Security\Abuse;

use App\Core\Log\AccessRequestMetadata;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final readonly class PassiveAbuseSignalSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AbuseRequestInspector $inspector,
        private SecuritySignalRecorder $signalRecorder,
        private AccessRequestMetadata $accessRequestMetadata,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -300],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $this->shouldSkip($event->getRequest()->getPathInfo())) {
            return;
        }

        try {
            $inspection = $this->inspector->inspect($event->getRequest());
            $profile = $inspection['profile'];
            $signal = $this->signalFor($profile);

            if (null === $signal) {
                return;
            }

            $subjects = $inspection['subjects'];
            $subject = $subjects->primary();
            if (null === $subject) {
                return;
            }

            $visitor = $subjects->first(AbuseSubjectType::Visitor);
            $ipBucket = $subjects->first(AbuseSubjectType::IpBucket);
            $cost = $inspection['cost'];
            $this->signalRecorder->record(
                $signal['type'],
                $signal['reason'],
                $subject->type()->value,
                $subject->identifier(),
                ipDerived: $subject->ipDerived(),
                severity: $signal['severity'],
                confidence: $signal['confidence'],
                requestFamily: $profile->family()->value,
                requestIntent: $profile->intent()->value,
                requestId: $this->accessRequestMetadata->requestId($event->getRequest()),
                visitorId: $visitor?->identifier() ?? 'n/a',
                path: $profile->path(),
                route: $profile->route(),
                httpStatus: $event->getResponse()->getStatusCode(),
                context: [
                    'ip_bucket' => $ipBucket?->identifier(),
                    'cost_bucket' => $cost->bucketFamily(),
                    'cost_credits' => $cost->credits(),
                    'ordinary_enforcement' => $cost->ordinaryEnforcement(),
                ],
            );
        } catch (Throwable) {
            return;
        }
    }

    /**
     * @return array{type: string, reason: string, severity: string, confidence: int}|null
     */
    private function signalFor(AbuseRequestProfile $profile): ?array
    {
        if ($profile->suspiciousProbe()) {
            return [
                'type' => 'probe',
                'reason' => 'security.signal.suspicious_probe',
                'severity' => 'WARNING',
                'confidence' => 95,
            ];
        }

        if ($profile->prefetch() && !in_array($profile->method(), ['GET', 'HEAD'], true)) {
            return [
                'type' => 'prefetch',
                'reason' => 'security.signal.prefetch_unsafe_method',
                'severity' => 'NOTICE',
                'confidence' => 60,
            ];
        }

        return null;
    }

    private function shouldSkip(string $path): bool
    {
        return str_starts_with($path, '/_profiler')
            || str_starts_with($path, '/_wdt')
            || str_starts_with($path, '/assets/')
            || str_starts_with($path, '/build/');
    }
}
