<?php

declare(strict_types=1);

namespace App\Security\Abuse;

use App\Core\Access\AccessLevel;
use App\Core\Log\AccessRequestMetadata;
use App\Core\Routing\IgnorableRequestPathMatcher;
use App\Security\AutoBan\AutoBanPolicy;
use App\Security\AutoBan\AutoBanRequestSubscriber;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final readonly class SuspiciousPayloadSignalSubscriber implements EventSubscriberInterface
{
    private IgnorableRequestPathMatcher $ignorablePaths;

    public function __construct(
        private SuspiciousRequestPayloadMatcher $payloadMatcher,
        private AbuseRequestInspector $inspector,
        private SecuritySignalRecorder $signalRecorder,
        private AccessRequestMetadata $accessRequestMetadata,
        private ?AutoBanPolicy $autoBanPolicy = null,
        ?IgnorableRequestPathMatcher $ignorablePaths = null,
    ) {
        $this->ignorablePaths = $ignorablePaths ?? new IgnorableRequestPathMatcher();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 2],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE) || $this->ignorablePaths->matches($request->getPathInfo())) {
            return;
        }

        try {
            $inspection = $this->inspector->inspect($request);
            $subjects = $inspection['subjects'];
            $profile = $inspection['profile'];
            if ($this->safeApplicationInput($profile) || $this->trustedContext($subjects)) {
                return;
            }

            $match = $this->payloadMatcher->match($request);
            if (null === $match) {
                return;
            }

            $sourceSubjects = $this->sourceSubjects($subjects);
            if ([] === $sourceSubjects) {
                return;
            }

            $visitor = $subjects->first(AbuseSubjectType::Visitor);
            $ipBucket = $subjects->first(AbuseSubjectType::IpBucket);
            $cost = $inspection['cost'];

            foreach ($sourceSubjects as $subject) {
                $this->signalRecorder->record(
                    'payload_probe',
                    'security.signal.suspicious_payload',
                    $subject->type()->value,
                    $subject->identifier(),
                    ipDerived: $subject->ipDerived(),
                    severity: 'WARNING',
                    confidence: 90,
                    requestFamily: $profile->family()->value,
                    requestIntent: $profile->intent()->value,
                    requestId: $this->accessRequestMetadata->requestId($request),
                    visitorId: $visitor?->identifier() ?? 'n/a',
                    path: $this->accessRequestMetadata->sanitizedPath($request),
                    route: $profile->route(),
                    context: [
                        'ip_bucket' => $ipBucket?->identifier(),
                        'cost_bucket' => $cost->bucketFamily(),
                        'cost_credits' => $cost->credits(),
                        'ordinary_enforcement' => $cost->ordinaryEnforcement(),
                        'payload_signatures' => $match['signatures'],
                        'payload_parameters' => $match['parameters'],
                    ],
                );
            }
        } catch (Throwable) {
            return;
        }
    }

    /**
     * @return list<AbuseSubject>
     */
    private function sourceSubjects(AbuseSubjectResolution $subjects): array
    {
        return array_values(array_filter([
            $subjects->first(AbuseSubjectType::Visitor),
            $subjects->first(AbuseSubjectType::IpBucket),
        ]));
    }

    private function safeApplicationInput(AbuseRequestProfile $profile): bool
    {
        return in_array($profile->family(), [RequestFamily::Admin, RequestFamily::Editor, RequestFamily::Setup], true);
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
}
