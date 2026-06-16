<?php

declare(strict_types=1);

namespace App\Security\Abuse;

use App\Content\Routing\ContentRouteLocalization;
use Symfony\Component\HttpFoundation\Request;

final readonly class RequestIntentClassifier
{
    public function __construct(
        private SuspiciousProbePathMatcher $probePathMatcher = new SuspiciousProbePathMatcher(),
        private ?ContentRouteLocalization $routeLocalization = null,
    ) {
    }

    public function classify(Request $request): AbuseRequestProfile
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getPathInfo();
        $segments = $this->segments($request);
        $route = $this->route($request);
        $family = $this->family($segments);
        $prefetch = $this->isPrefetch($request);
        $suspiciousProbe = $this->probePathMatcher->isProbe($path);

        return new AbuseRequestProfile(
            $family,
            $this->intent($method, $segments, $route, $family, $prefetch, $suspiciousProbe),
            $method,
            substr($path, 0, 1024),
            $route,
            $prefetch,
            $suspiciousProbe,
        );
    }

    /**
     * @param list<string> $segments
     */
    private function family(array $segments): RequestFamily
    {
        return match (true) {
            $this->matchesSegments($segments, 'api', 'live') => RequestFamily::LiveApi,
            $this->matchesSegments($segments, 'api') => RequestFamily::Api,
            $this->matchesSegments($segments, 'cron') => RequestFamily::Scheduler,
            $this->matchesSegments($segments, 'setup') => RequestFamily::Setup,
            $this->matchesSegments($segments, 'admin') => RequestFamily::Admin,
            $this->matchesSegments($segments, 'editor') => RequestFamily::Editor,
            default => RequestFamily::Browser,
        };
    }

    private function intent(
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

        if ('OPTIONS' === $method) {
            return RequestIntent::CorsPreflight;
        }

        if (RequestFamily::Scheduler === $family) {
            return RequestIntent::SchedulerTrigger;
        }

        if (RequestFamily::LiveApi === $family) {
            return RequestIntent::LiveApi;
        }

        if (RequestFamily::Api === $family) {
            if ($this->matchesSegments($segments, 'api', 'v1', 'admin') && !$this->safeMethod($method)) {
                return $this->adminMutationIntent($this->apiAdminSegments($segments), $route);
            }

            return in_array($method, ['GET', 'HEAD'], true) ? RequestIntent::ApiRead : RequestIntent::ApiWrite;
        }

        if ($prefetch && 'GET' === $method) {
            return RequestIntent::TurboPrefetch;
        }

        if (RequestFamily::Setup === $family && !$this->safeMethod($method)) {
            return RequestIntent::SetupApply;
        }

        if (RequestFamily::Admin === $family && !$this->safeMethod($method)) {
            return $this->adminMutationIntent($segments, $route);
        }

        return match (true) {
            $this->routeIs($route, 'user_login') || $this->matchesSegments($segments, 'user', 'login') => RequestIntent::Login,
            $this->routeIs($route, 'user_register', 'user_invitation_accept') || $this->matchesSegments($segments, 'user', 'register') || $this->matchesSegments($segments, 'user', 'invitation') => RequestIntent::Registration,
            $this->routeIs($route, 'user_reset_password', 'user_password_reset_token', 'user_security_review') || $this->matchesSegments($segments, 'user', 'password-reset') || $this->matchesSegments($segments, 'user', 'reset-password') || $this->matchesSegments($segments, 'user', 'security-review') => RequestIntent::PasswordReset,
            $this->routeHasToken($route, 'contact') || $this->matchesSegments($segments, 'contact') => RequestIntent::Contact,
            $this->routeHasTokens($route, 'captcha', 'refresh') || $this->matchesSegments($segments, 'captcha', 'refresh') => RequestIntent::CaptchaRefresh,
            $this->routeHasTokens($route, 'captcha', 'failure') || $this->matchesSegments($segments, 'captcha', 'failure') => RequestIntent::CaptchaFailure,
            !$this->safeMethod($method) => RequestIntent::FormSubmit,
            default => RequestIntent::BrowserNavigation,
        };
    }

    private function adminMutationIntent(array $segments, string $route): RequestIntent
    {
        return match (true) {
            $this->matchesSegments($segments, 'admin', 'settings') || $this->routeHasToken($route, 'settings') => RequestIntent::SettingsMutation,
            $this->matchesSegments($segments, 'admin', 'users') || $this->routeHasToken($route, 'users') || $this->routeHasToken($route, 'acl') => RequestIntent::UserAclMutation,
            $this->matchesSegments($segments, 'admin', 'packages') || $this->routeHasToken($route, 'package') || $this->routeHasToken($route, 'packages') => RequestIntent::PackageAdminOperation,
            $this->hasSegment($segments, 'upload', 'archive', 'media') || $this->routeHasAnyToken($route, 'upload', 'archive', 'media') => RequestIntent::UploadArchiveValidation,
            $this->hasSegment($segments, 'export', 'download') || $this->routeHasAnyToken($route, 'export', 'download') => RequestIntent::ExportDownload,
            $this->hasSegment($segments, 'import') || $this->routeHasToken($route, 'import') => RequestIntent::ImportOperation,
            $this->hasSegment($segments, 'backup', 'restore') || $this->routeHasAnyToken($route, 'backup', 'restore') => RequestIntent::BackupRestore,
            $this->hasSegment($segments, 'diagnostic', 'diagnostics', 'support') || $this->routeHasAnyToken($route, 'diagnostic', 'diagnostics', 'support') => RequestIntent::DiagnosticsSupport,
            default => RequestIntent::AdminOperation,
        };
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

    private function routeHasTokens(string $route, string ...$tokens): bool
    {
        $routeTokens = $this->routeTokens($route);

        foreach ($tokens as $token) {
            if (!in_array($token, $routeTokens, true)) {
                return false;
            }
        }

        return [] !== $tokens;
    }

    /**
     * @return list<string>
     */
    private function routeTokens(string $route): array
    {
        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($route)) ?: [],
            static fn (string $token): bool => '' !== $token,
        ));
    }

    /**
     * @return list<string>
     */
    private function segments(Request $request): array
    {
        $segments = array_values(array_filter(explode('/', trim($request->getPathInfo(), '/')), static fn (string $segment): bool => '' !== $segment));
        $locale = $this->localePrefix($request);

        if (is_string($locale) && '' !== $locale && ($segments[0] ?? null) === $locale) {
            array_shift($segments);
        }

        return $segments;
    }

    private function localePrefix(Request $request): ?string
    {
        $segments = explode('/', trim($request->getPathInfo(), '/'));
        $firstSegment = $segments[0] ?? '';

        if ('' === $firstSegment || !$this->hasLocalizedReservedPath($segments)) {
            return null;
        }

        $locale = $request->attributes->get('_locale');
        if (is_string($locale) && $firstSegment === $locale) {
            return $firstSegment;
        }

        if (null !== $this->routeLocalization && $this->routeLocalization->isEnabled() && in_array($firstSegment, $this->routeLocalization->availableLanguages(), true)) {
            return $firstSegment;
        }

        return null;
    }

    /**
     * @param list<string> $pathSegments
     */
    private function matchesSegments(array $pathSegments, string ...$segments): bool
    {
        foreach ($segments as $index => $segment) {
            if (($pathSegments[$index] ?? null) !== $segment) {
                return false;
            }
        }

        return [] !== $segments;
    }

    /**
     * @param list<string> $pathSegments
     */
    private function hasSegment(array $pathSegments, string ...$segments): bool
    {
        foreach ($segments as $segment) {
            if (in_array($segment, $pathSegments, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $segments
     *
     * @return list<string>
     */
    private function apiAdminSegments(array $segments): array
    {
        return $this->matchesSegments($segments, 'api', 'v1', 'admin')
            ? ['admin', ...array_slice($segments, 3)]
            : $segments;
    }

    /**
     * @param list<string> $segments
     */
    private function hasLocalizedReservedPath(array $segments): bool
    {
        return in_array($segments[1] ?? '', ['admin', 'api', 'captcha', 'contact', 'cron', 'editor', 'setup', 'user'], true);
    }
}
