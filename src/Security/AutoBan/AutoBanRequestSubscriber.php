<?php

declare(strict_types=1);

namespace App\Security\AutoBan;

use App\Core\Access\AccessLevel;
use App\Core\Log\AccessRequestMetadata;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Core\Routing\RequestPathResolver;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseRequestProfile;
use App\Security\Abuse\AbuseSubject;
use App\Security\Abuse\AbuseSubjectResolution;
use App\Security\Abuse\AbuseSubjectType;
use App\Security\Abuse\RequestIntent;
use App\Security\Abuse\SuspiciousProbePathMatcher;
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
    public const PROBE_RATE_LIMIT_SKIP_ATTRIBUTE = '_system_auto_ban_skip_probe_rate_limit';
    public const TRUSTED_PRE_AUTH_BYPASS_ATTRIBUTE = '_system_auto_ban_trusted_pre_auth_bypass';
    public const RECOVERY_LOGIN_TOKEN_FIELD = '_auto_ban_recovery_token';
    public const RECOVERY_LOGIN_TOKEN_ID = 'auto_ban_recovery_login';

    private SuspiciousProbePathMatcher $probePaths;
    private RequestPathResolver $paths;

    public function __construct(
        private AbuseRequestInspector $inspector,
        private AutoBanPolicy $policy,
        private AutoBanStore $store,
        private HttpErrorRenderer $httpError,
        private AccessRequestMetadata $requestMetadata,
        private string $environment = 'prod',
        private ?MessageReporterInterface $messageReporter = null,
        private ClockInterface $clock = new NativeClock(),
        ?SuspiciousProbePathMatcher $probePaths = null,
        ?RequestPathResolver $paths = null,
        private ?CsrfTokenManagerInterface $csrfTokens = null,
        private ?TrustedApiKeyAutoBanBypass $trustedApiKeys = null,
    ) {
        $this->probePaths = $probePaths ?? new SuspiciousProbePathMatcher();
        $this->paths = $paths ?? new RequestPathResolver();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['onKernelRequestPreAuthSourceBan', 4098],
                ['onKernelRequestProbeCandidate', 4097],
                ['onKernelRequestLogin', 16],
                ['onKernelRequest', 4],
            ],
        ];
    }

    public function onKernelRequestPreAuthSourceBan(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $protectedSurface = $this->preAuthProtectedSurface($request);
        if (!$event->isMainRequest() || $event->hasResponse() || !$this->enabledForRequest($request) || !$this->policy->enabled() || null === $protectedSurface) {
            return;
        }

        try {
            $ban = $this->activeBanFor($this->inspector->inspect($request)['subjects']);
            if (!$ban instanceof ActiveAutoBan) {
                return;
            }

            if ($this->trustedApiKeys?->allows(
                $request,
                allowPrefixlessBearer: 'scheduler' === $protectedSurface,
                allowSchedulerQuery: 'scheduler' === $protectedSurface,
            )) {
                $request->attributes->set(self::TRUSTED_PRE_AUTH_BYPASS_ATTRIBUTE, true);

                return;
            }

            $event->setResponse($this->banResponse($request, $ban));
            $request->attributes->set(self::PASSIVE_SIGNAL_SKIP_ATTRIBUTE, true);
        } catch (Throwable $error) {
            $this->reportEvaluation($error, [
                'operation' => 'api_source_ban',
                'path' => $request->getPathInfo(),
            ]);

            return;
        }
    }

    public function onKernelRequestProbeCandidate(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->enabledForRequest($request) || !$this->policy->enabled() || !$this->probePaths->isProbe($request->getPathInfo())) {
            return;
        }

        try {
            $inspection = $this->inspector->inspect($request);
            if ($this->activeBanFor($inspection['subjects']) instanceof ActiveAutoBan) {
                $request->attributes->set(self::PROBE_RATE_LIMIT_SKIP_ATTRIBUTE, true);
            }
        } catch (Throwable $error) {
            $this->reportEvaluation($error, [
                'operation' => 'probe_candidate',
                'path' => $request->getPathInfo(),
            ]);

            return;
        }
    }

    public function onKernelRequestLogin(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $event->hasResponse() || !$this->enabledForRequest($request) || !$this->policy->enabled() || !$this->loginSubmissionCandidate($request)) {
            return;
        }

        try {
            $inspection = $this->inspector->inspect($request);
            if ($this->recoveryRequest($request, $inspection['profile'])) {
                return;
            }

            $ban = $this->activeBanFor($inspection['subjects']);
            if (!$ban instanceof ActiveAutoBan) {
                return;
            }

            $event->setResponse($this->banResponse($request, $ban));
            $request->attributes->set(self::PASSIVE_SIGNAL_SKIP_ATTRIBUTE, true);
        } catch (Throwable $error) {
            $this->reportEvaluation($error, [
                'operation' => 'login_enforcement',
                'path' => $request->getPathInfo(),
            ]);

            return;
        }
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->enabledForRequest($event->getRequest()) || !$this->policy->enabled()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->attributes->getBoolean(self::TRUSTED_PRE_AUTH_BYPASS_ATTRIBUTE)) {
            return;
        }

        try {
            $inspection = $this->inspector->inspect($request);
            if ($this->recoveryRenderRequest($inspection['profile']) || $this->trustedContext($inspection['subjects']->subjects())) {
                return;
            }

            $ban = $this->activeBanFor($inspection['subjects']);
            if ($ban instanceof ActiveAutoBan) {
                $event->setResponse($this->banResponse($request, $ban));
                $request->attributes->set(self::PASSIVE_SIGNAL_SKIP_ATTRIBUTE, true);
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

    private function activeBanFor(AbuseSubjectResolution $subjects): ?ActiveAutoBan
    {
        foreach ([AbuseSubjectType::Visitor, AbuseSubjectType::IpBucket] as $type) {
            $subject = $subjects->first($type);
            if (!$subject instanceof AbuseSubject) {
                continue;
            }

            $autoBanSubject = AutoBanSubject::fromAbuseSubject($subject);
            if (!$autoBanSubject instanceof AutoBanSubject) {
                continue;
            }

            $ban = $this->store->active($autoBanSubject);
            if ($ban instanceof ActiveAutoBan) {
                return $ban;
            }
        }

        return null;
    }

    private function recoveryRequest(Request $request, AbuseRequestProfile $profile): bool
    {
        if ($this->recoveryRenderRequest($profile)) {
            return true;
        }

        return RequestIntent::Login === $profile->intent()
            && 'POST' === $profile->method()
            && in_array($profile->route(), ['user_login', 'n/a'], true)
            && $this->validRecoveryLoginToken($request);
    }

    private function recoveryRenderRequest(AbuseRequestProfile $profile): bool
    {
        return RequestIntent::RecoveryLogin === $profile->intent();
    }

    private function validRecoveryLoginToken(Request $request): bool
    {
        $token = $request->request->all()[self::RECOVERY_LOGIN_TOKEN_FIELD] ?? null;

        return is_string($token)
            && '' !== $token
            && $this->csrfTokens instanceof CsrfTokenManagerInterface
            && $this->csrfTokens->isTokenValid(new CsrfToken(self::RECOVERY_LOGIN_TOKEN_ID, $token));
    }

    private function loginSubmissionCandidate(Request $request): bool
    {
        if ('POST' !== strtoupper($request->getMethod())) {
            return false;
        }

        $route = $request->attributes->get('_route');
        if ('user_login' === $route) {
            return true;
        }

        return $this->paths->matchesExact($request, 'user', 'login');
    }

    private function preAuthProtectedSurface(Request $request): ?string
    {
        $segments = array_values(array_filter(explode('/', trim($request->getPathInfo(), '/')), static fn (string $segment): bool => '' !== $segment));

        if ('api' === ($segments[0] ?? null) && 'v1' === ($segments[1] ?? null)) {
            return 'api';
        }

        return ['cron', 'run'] === $segments ? 'scheduler' : null;
    }

    private function banResponse(Request $request, ActiveAutoBan $ban): Response
    {
        $retryAfter = $ban->retryAfterSeconds($this->clock->now());
        $request->attributes->set(AccessRequestMetadata::FORCE_ACCESS_LOG_ATTRIBUTE, true);

        return $this->httpError->bare(Response::HTTP_FORBIDDEN, $request, [
            'request_id' => $this->requestMetadata->requestId($request),
            'bare_context' => 'Request blocked due to suspicious activity. retry-after: '.$retryAfter,
        ], [
            'Retry-After' => (string) $retryAfter,
        ]);
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
