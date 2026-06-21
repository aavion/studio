<?php

declare(strict_types=1);

namespace App\Security\Abuse;

use App\Core\Access\AccessLevel;
use App\Core\Log\AccessRequestMetadata;
use App\Core\Routing\IgnorableRequestPathMatcher;
use App\Security\AutoBan\AutoBanPolicy;
use App\Security\AutoBan\AutoBanRequestSubscriber;
use App\Security\AutoBan\TrustedApiKeyAutoBanBypass;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final readonly class PassiveAbuseSignalSubscriber implements EventSubscriberInterface
{
    private IgnorableRequestPathMatcher $ignorablePaths;

    public function __construct(
        private AbuseRequestInspector $inspector,
        private SecuritySignalRecorder $signalRecorder,
        private AccessRequestMetadata $accessRequestMetadata,
        private ?AutoBanPolicy $autoBanPolicy = null,
        ?IgnorableRequestPathMatcher $ignorablePaths = null,
        private ?TrustedApiKeyAutoBanBypass $trustedApiKeys = null,
    ) {
        $this->ignorablePaths = $ignorablePaths ?? new IgnorableRequestPathMatcher();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -300],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE) || $this->shouldSkip($event->getRequest()->getPathInfo())) {
            return;
        }

        try {
            $inspection = $this->inspector->inspect($event->getRequest());
            $profile = $inspection['profile'];
            $signal = $this->signalFor($profile, $event->getResponse()->getStatusCode());

            if (null === $signal) {
                return;
            }

            if ($signal['source_scored'] && $this->trustedSchedulerCredential($event->getRequest(), $profile)) {
                return;
            }

            $subjects = $inspection['subjects'];
            $sourceSubjects = $this->sourceSubjects($subjects, $signal['source_scored']);
            if ([] === $sourceSubjects) {
                return;
            }

            $visitor = $subjects->first(AbuseSubjectType::Visitor);
            $ipBucket = $subjects->first(AbuseSubjectType::IpBucket);
            $cost = $inspection['cost'];

            foreach ($sourceSubjects as $subject) {
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
                    path: $this->accessRequestMetadata->sanitizedPath($event->getRequest()),
                    route: $profile->route(),
                    httpStatus: $event->getResponse()->getStatusCode(),
                    context: [
                        'ip_bucket' => $ipBucket?->identifier(),
                        'cost_bucket' => $cost->bucketFamily(),
                        'cost_credits' => $cost->credits(),
                        'ordinary_enforcement' => $cost->ordinaryEnforcement(),
                    ],
                );
            }
        } catch (Throwable) {
            return;
        }
    }

    /**
     * @return array{type: string, reason: string, severity: string, confidence: int, source_scored: bool}|null
     */
    private function signalFor(AbuseRequestProfile $profile, int $statusCode): ?array
    {
        if ($profile->suspiciousProbe()) {
            return [
                'type' => 'probe',
                'reason' => 'security.signal.suspicious_probe',
                'severity' => 'WARNING',
                'confidence' => 95,
                'source_scored' => true,
            ];
        }

        if ($profile->prefetch() && !in_array($profile->method(), ['GET', 'HEAD'], true)) {
            return [
                'type' => 'prefetch',
                'reason' => 'security.signal.prefetch_unsafe_method',
                'severity' => 'NOTICE',
                'confidence' => 60,
                'source_scored' => false,
            ];
        }

        if (in_array($statusCode, [400, 403, 404, 429], true)) {
            return [
                'type' => 'http_error',
                'reason' => 'security.signal.error_http_status',
                'severity' => 'NOTICE',
                'confidence' => 40,
                'source_scored' => true,
            ];
        }

        return null;
    }

    /**
     * @return list<AbuseSubject>
     */
    private function sourceSubjects(AbuseSubjectResolution $subjects, bool $sourceScored): array
    {
        if (!$sourceScored) {
            $primary = $subjects->primary();

            return null === $primary ? [] : [$primary];
        }

        if ($this->trustedContext($subjects)) {
            $user = $subjects->first(AbuseSubjectType::User);

            return null === $user ? [] : [$user];
        }

        return array_values(array_filter([
            $subjects->first(AbuseSubjectType::Visitor),
            $subjects->first(AbuseSubjectType::IpBucket),
        ]));
    }

    private function trustedContext(AbuseSubjectResolution $subjects): bool
    {
        $user = $subjects->first(AbuseSubjectType::User);
        if (!$user instanceof AbuseSubject) {
            return false;
        }

        $level = $user->context()['access_level'] ?? AccessLevel::PUBLIC;

        return is_numeric($level) && (int) $level >= ($this->autoBanPolicy?->trustedAccessLevel() ?? AccessLevel::MANAGER);
    }

    private function trustedSchedulerCredential(Request $request, AbuseRequestProfile $profile): bool
    {
        return RequestIntent::SchedulerTrigger === $profile->intent()
            && true === $this->trustedApiKeys?->allows($request, allowPrefixlessBearer: true, allowSchedulerQuery: true);
    }

    private function shouldSkip(string $path): bool
    {
        return $this->ignorablePaths->matches($path);
    }
}
