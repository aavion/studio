<?php

declare(strict_types=1);

namespace App\Security\Abuse;

use App\Api\Security\ApiRequestMethodPolicy;
use App\Content\Routing\ContentRouteLocalization;
use App\Core\Routing\PathScopeMatcher;
use App\Core\Routing\RequestPathResolver;
use Symfony\Component\HttpFoundation\Request;

final readonly class RequestIntentClassifier
{
    private RequestPathResolver $paths;
    private PathScopeMatcher $rawPaths;

    public function __construct(
        private SuspiciousProbePathMatcher $probePathMatcher = new SuspiciousProbePathMatcher(),
        ?ContentRouteLocalization $routeLocalization = null,
        private ApiRequestMethodPolicy $apiMethods = new ApiRequestMethodPolicy(),
        ?RequestPathResolver $paths = null,
        ?PathScopeMatcher $rawPaths = null,
    ) {
        $this->paths = $paths ?? new RequestPathResolver($routeLocalization);
        $this->rawPaths = $rawPaths ?? new PathScopeMatcher();
    }

    public function classify(Request $request): AbuseRequestProfile
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getPathInfo();
        $segments = $this->segments($request);
        $route = $this->route($request);
        $family = $this->family($request, $segments);
        $prefetch = $this->isPrefetch($request);
        $suspiciousProbe = $this->probePathMatcher->isProbe($path);

        return new AbuseRequestProfile(
            $family,
            $this->intent($request, $method, $segments, $route, $family, $prefetch, $suspiciousProbe),
            $method,
            substr($path, 0, 1024),
            $route,
            $prefetch,
            $suspiciousProbe,
        );
    }

    private function family(Request $request, array $segments): RequestFamily
    {
        $rawPath = $request->getPathInfo();

        return match (true) {
            $this->rawPaths->matchesSegments($rawPath, 'api', 'live') => RequestFamily::LiveApi,
            $this->rawPaths->matchesSegments($rawPath, 'api') => RequestFamily::Api,
            $this->rawPaths->matchesSegments($rawPath, 'cron') => RequestFamily::Scheduler,
            $this->rawPaths->matchesSegments($rawPath, 'setup') => RequestFamily::Setup,
            $this->matchesSegments($segments, 'admin') => RequestFamily::Admin,
            $this->matchesSegments($segments, 'editor') => RequestFamily::Editor,
            default => RequestFamily::Browser,
        };
    }

    private function intent(
        Request $request,
        string $method,
        array $segments,
        string $route,
        RequestFamily $family,
        bool $prefetch,
        bool $suspiciousProbe,
    ): RequestIntent {
        if ($suspiciousProbe) {
            return RequestIntent::SuspiciousProbe;
        }

        if (RequestFamily::Scheduler === $family) {
            return $this->schedulerTrigger($request)
                ? RequestIntent::SchedulerTrigger
                : RequestIntent::BrowserNavigation;
        }

        if (RequestFamily::LiveApi === $family) {
            return RequestIntent::LiveApi;
        }

        if (RequestFamily::Api === $family) {
            if ('OPTIONS' === $method) {
                if ($this->apiMethods->hasAuthorizationHeader($request)) {
                    return $this->apiIntentForMethod($this->apiMethods->effectiveMethod($request), $segments, $route);
                }

                return RequestIntent::CorsPreflight;
            }

            if ($this->matchesSegments($segments, 'api', 'v1', 'admin') && !$this->safeMethod($method)) {
                return $this->adminMutationIntent($this->apiAdminSegments($segments), $route);
            }

            return $this->apiIntentForMethod($method, $segments, $route);
        }

        if ('OPTIONS' === $method) {
            return RequestIntent::CorsPreflight;
        }

        if (RequestFamily::Setup === $family && !$this->safeMethod($method)) {
            return $this->setupApply($request, $segments)
                ? RequestIntent::SetupApply
                : RequestIntent::BrowserNavigation;
        }

        $adminReadIntent = RequestFamily::Admin === $family ? $this->adminReadIntent($segments, $route) : null;
        if ($adminReadIntent instanceof RequestIntent) {
            return $adminReadIntent;
        }

        if (RequestFamily::Admin === $family && !$this->safeMethod($method)) {
            return $this->adminMutationIntent($segments, $route);
        }

        if ($this->recoveryLogin($request, $method, $segments, $route)) {
            return RequestIntent::RecoveryLogin;
        }

        if ($prefetch && 'GET' === $method) {
            return RequestIntent::TurboPrefetch;
        }

        return match (true) {
            !$this->safeMethod($method) && ($this->routeIs($route, 'user_login') || $this->loginSegments($segments)) => RequestIntent::Login,
            !$this->safeMethod($method) && ($this->routeIs($route, 'user_register', 'user_invitation_accept') || $this->matchesSegments($segments, 'user', 'register') || $this->matchesSegments($segments, 'user', 'invitation')) => RequestIntent::Registration,
            !$this->safeMethod($method) && ($this->routeIs($route, 'user_reset_password', 'user_password_reset_token', 'user_security_review') || $this->matchesSegments($segments, 'user', 'password-reset') || $this->matchesSegments($segments, 'user', 'reset-password') || $this->matchesSegments($segments, 'user', 'security-review')) => RequestIntent::PasswordReset,
            !$this->safeMethod($method) => RequestIntent::FormSubmit,
            default => RequestIntent::BrowserNavigation,
        };
    }

    private function recoveryLogin(Request $request, string $method, array $segments, string $route): bool
    {
        return 'GET' === $method
            && $this->loginSegments($segments)
            && $this->routeIs($route, 'user_login', 'n/a')
            && '1' === $this->scalarQueryValue($request, 'bypass', '');
    }

    /**
     * @param list<string> $segments
     */
    private function loginSegments(array $segments): bool
    {
        return $this->matchesSegments($segments, 'user', 'login');
    }

    private function setupApply(Request $request, array $segments): bool
    {
        return $this->matchesExactSegments($segments, 'setup', 'review')
            && 'apply' === $this->scalarRequestValue($request, '_setup_action', '');
    }

    private function schedulerTrigger(Request $request): bool
    {
        return $this->rawPaths->matchesExactSegments($request->getPathInfo(), 'cron', 'run');
    }

    private function adminMutationIntent(array $segments, string $route): RequestIntent
    {
        return match (true) {
            $this->matchesSegments($segments, 'admin', 'settings') || $this->routeHasToken($route, 'settings') => RequestIntent::SettingsMutation,
            $this->matchesSegments($segments, 'admin', 'users') || $this->routeHasToken($route, 'users') || $this->routeHasToken($route, 'acl') => RequestIntent::UserAclMutation,
            $this->hasSegment($segments, 'upload', 'archive', 'media') || $this->routeHasAnyToken($route, 'upload', 'archive', 'media') => RequestIntent::UploadArchiveValidation,
            $this->hasSegment($segments, 'export', 'download') || $this->routeHasAnyToken($route, 'export', 'download') => RequestIntent::ExportDownload,
            $this->matchesSegments($segments, 'admin', 'packages') || $this->routeHasToken($route, 'package') || $this->routeHasToken($route, 'packages') => RequestIntent::PackageAdminOperation,
            $this->hasSegment($segments, 'import') || $this->routeHasToken($route, 'import') => RequestIntent::ImportOperation,
            $this->hasSegment($segments, 'backup', 'restore') || $this->routeHasAnyToken($route, 'backup', 'restore') => RequestIntent::BackupRestore,
            $this->hasSegment($segments, 'diagnostic', 'diagnostics', 'support') || $this->routeHasAnyToken($route, 'diagnostic', 'diagnostics', 'support') => RequestIntent::DiagnosticsSupport,
            default => RequestIntent::AdminOperation,
        };
    }

    private function adminReadIntent(array $segments, string $route): ?RequestIntent
    {
        return match (true) {
            $this->hasSegment($segments, 'export', 'download') || $this->routeHasAnyToken($route, 'export', 'download') => RequestIntent::ExportDownload,
            $this->hasSegment($segments, 'diagnostic', 'diagnostics', 'support') || $this->routeHasAnyToken($route, 'diagnostic', 'diagnostics', 'support') => RequestIntent::DiagnosticsSupport,
            default => null,
        };
    }

    private function apiIntentForMethod(string $method, array $segments, string $route): RequestIntent
    {
        $method = strtoupper($method);
        if ($this->matchesSegments($segments, 'api', 'v1', 'admin') && !$this->safeMethod($method)) {
            return $this->adminMutationIntent($this->apiAdminSegments($segments), $route);
        }

        return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) ? RequestIntent::ApiRead : RequestIntent::ApiWrite;
    }

    private function isPrefetch(Request $request): bool
    {
        foreach (['Sec-Purpose', 'X-Sec-Purpose', 'Purpose'] as $header) {
            $value = strtolower((string) $request->headers->get($header, ''));
            if (str_contains($value, 'prefetch')) {
                return true;
            }
        }

        return false;
    }

    private function safeMethod(string $method): bool
    {
        return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function route(Request $request): string
    {
        $route = $request->attributes->get('_route');

        return is_string($route) && '' !== $route ? substr($route, 0, 190) : 'n/a';
    }

    private function routeIs(string $route, string ...$routes): bool
    {
        return in_array($route, $routes, true);
    }

    private function routeHasToken(string $route, string $token): bool
    {
        return in_array($token, $this->routeTokens($route), true);
    }

    private function routeHasAnyToken(string $route, string ...$tokens): bool
    {
        return [] !== array_intersect($tokens, $this->routeTokens($route));
    }

    private function routeTokens(string $route): array
    {
        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($route)) ?: [],
            static fn (string $token): bool => '' !== $token,
        ));
    }

    private function segments(Request $request): array
    {
        return $this->paths->segments($request);
    }

    private function matchesSegments(array $pathSegments, string ...$segments): bool
    {
        foreach ($segments as $index => $segment) {
            if (($pathSegments[$index] ?? null) !== $segment) {
                return false;
            }
        }

        return [] !== $segments;
    }

    private function matchesExactSegments(array $pathSegments, string ...$segments): bool
    {
        return count($pathSegments) === count($segments) && $this->matchesSegments($pathSegments, ...$segments);
    }

    private function hasSegment(array $pathSegments, string ...$segments): bool
    {
        foreach ($segments as $segment) {
            if (in_array($segment, $pathSegments, true)) {
                return true;
            }
        }

        return false;
    }

    private function apiAdminSegments(array $segments): array
    {
        return $this->matchesSegments($segments, 'api', 'v1', 'admin')
            ? ['admin', ...array_slice($segments, 3)]
            : $segments;
    }

    private function scalarRequestValue(Request $request, string $name, string $default = ''): string
    {
        $value = $request->request->all()[$name] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    private function scalarQueryValue(Request $request, string $name, string $default = ''): string
    {
        $value = $request->query->all()[$name] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

}
