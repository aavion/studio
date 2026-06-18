<?php

declare(strict_types=1);

namespace App\Security\AutoBan;

use App\Core\Access\AccessLevel;
use App\Core\Log\AccessRequestMetadata;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Core\Routing\IgnorableRequestPathMatcher;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseRequestProfile;
use App\Security\Abuse\AbuseSubject;
use App\Security\Abuse\AbuseSubjectType;
use App\Security\Abuse\RequestIntent;
use App\View\Http\HttpErrorRenderer;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;

final readonly class AutoBanRequestSubscriber implements EventSubscriberInterface
{
    public const PASSIVE_SIGNAL_SKIP_ATTRIBUTE = '_system_auto_ban_response';
    public const RECOVERY_LOGIN_TOKEN_FIELD = '_auto_ban_recovery_token';
    public const RECOVERY_LOGIN_TOKEN_ID = 'auto_ban_recovery_login';

    private IgnorableRequestPathMatcher $ignorablePaths;

    public function __construct(
        private AbuseRequestInspector $inspector,
        private AutoBanPolicy $policy,
        private AutoBanStore $store,
        private HttpErrorRenderer $httpError,
        private AccessRequestMetadata $requestMetadata,
        private string $environment = 'prod',
        private ?MessageReporterInterface $messageReporter = null,
        private ClockInterface $clock = new NativeClock(),
        ?IgnorableRequestPathMatcher $ignorablePaths = null,
        private ?CsrfTokenManagerInterface $csrfTokens = null,
    ) {
        $this->ignorablePaths = $ignorablePaths ?? new IgnorableRequestPathMatcher();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 4],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->enabledForRequest($event->getRequest()) || !$this->policy->enabled()) {
            return;
        }

        $request = $event->getRequest();
        if ($this->excludedRequest($request)) {
            return;
        }

        try {
            $inspection = $this->inspector->inspect($request);
            if ($this->recoveryRequest($request, $inspection['profile']) || $this->trustedContext($inspection['subjects']->subjects())) {
                return;
            }

            foreach ([AbuseSubjectType::Visitor, AbuseSubjectType::IpBucket] as $type) {
                $subject = $inspection['subjects']->first($type);
                if (!$subject instanceof AbuseSubject) {
                    continue;
                }

                $autoBanSubject = AutoBanSubject::fromAbuseSubject($subject);
                if (!$autoBanSubject instanceof AutoBanSubject) {
                    continue;
                }

                $ban = $this->store->active($autoBanSubject);
                if (!$ban instanceof ActiveAutoBan) {
                    continue;
                }

                $event->setResponse($this->banResponse($request, $ban));
                $request->attributes->set(self::PASSIVE_SIGNAL_SKIP_ATTRIBUTE, true);

                return;
            }
        } catch (Throwable $error) {
            $this->reportEvaluation($error, [
                'operation' => 'request_enforcement',
                'path' => $request->getPathInfo(),
            ]);

            return;
        }
    }

    /**
     * @param list<AbuseSubject> $subjects
     */
    private function trustedContext(array $subjects): bool
    {
        foreach ($subjects as $subject) {
            if (AbuseSubjectType::User !== $subject->type()) {
                continue;
            }

            $level = $subject->context()['access_level'] ?? AccessLevel::PUBLIC;

            return is_numeric($level) && (int) $level >= $this->policy->trustedAccessLevel();
        }

        return false;
    }

    private function recoveryRequest(Request $request, AbuseRequestProfile $profile): bool
    {
        if (RequestIntent::RecoveryLogin === $profile->intent()) {
            return true;
        }

        return RequestIntent::Login === $profile->intent()
            && 'POST' === $profile->method()
            && in_array($profile->route(), ['user_login', 'n/a'], true)
            && $this->validRecoveryLoginToken($request);
    }

    private function validRecoveryLoginToken(Request $request): bool
    {
        $token = $request->request->get(self::RECOVERY_LOGIN_TOKEN_FIELD);

        return is_string($token)
            && '' !== $token
            && $this->csrfTokens instanceof CsrfTokenManagerInterface
            && $this->csrfTokens->isTokenValid(new CsrfToken(self::RECOVERY_LOGIN_TOKEN_ID, $token));
    }

    private function banResponse(Request $request, ActiveAutoBan $ban): Response
    {
        $retryAfter = $ban->retryAfterSeconds($this->clock->now());

        return $this->httpError->bare(Response::HTTP_FORBIDDEN, $request, [
            'request_id' => $this->requestMetadata->requestId($request),
            'bare_context' => 'Request blocked due to suspicious activity. retry-after: '.$retryAfter,
        ], [
            'Retry-After' => (string) $retryAfter,
        ]);
    }

    private function excludedRequest(Request $request): bool
    {
        return $this->ignorablePaths->matches($request->getPathInfo());
    }

    private function enabledForRequest(Request $request): bool
    {
        return 'test' !== $this->environment || '1' === $request->headers->get('X-Auto-Ban-Testing');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function reportEvaluation(Throwable $error, array $context = []): void
    {
        try {
            $this->messageReporter?->report(Message::exception(
                SecurityMessageCode::AUTO_BAN_EVALUATION_DEGRADED,
                SecurityMessageKey::AUTO_BAN_EVALUATION_DEGRADED,
                context: [
                    'exception' => $error::class,
                    'message' => $error->getMessage(),
                    ...$context,
                ],
            ), ['component' => self::class]);
        } catch (Throwable) {
        }
    }
}
