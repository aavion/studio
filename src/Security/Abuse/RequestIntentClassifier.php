<?php

declare(strict_types=1);

namespace App\Security\Abuse;

use App\Localization\TranslationLanguageCatalog;
use Symfony\Component\HttpFoundation\Request;

final readonly class RequestIntentClassifier
{
    public function __construct(
        private SuspiciousProbePathMatcher $probePathMatcher = new SuspiciousProbePathMatcher(),
        private ?TranslationLanguageCatalog $languageCatalog = null,
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
            $this->intent($request, $method, $path, $segments, $route, $family, $prefetch, $suspiciousProbe),
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
        Request $request,
        string $method,
        string $path,
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
            return in_array($method, ['GET', 'HEAD'], true) ? RequestIntent::ApiRead : RequestIntent::ApiWrite;
        }

        if ($prefetch && 'GET' === $method) {
            return RequestIntent::TurboPrefetch;
        }

        if (RequestFamily::Setup === $family && !$this->safeMethod($method)) {
            return RequestIntent::SetupApply;
        }

        if (RequestFamily::Admin === $family && !$this->safeMethod($method)) {
            return $this->adminMutationIntent($path, $route);
        }

        return match (true) {
            $this->routeIs($route, 'user_login') || $this->matchesSegments($segments, 'user', 'login') => RequestIntent::Login,
            $this->routeIs($route, 'user_register', 'user_invitation_accept') || $this->matchesSegments($segments, 'user', 'register') || $this->matchesSegments($segments, 'user', 'invitation') => RequestIntent::Registration,
            $this->routeIs($route, 'user_reset_password', 'user_password_reset_token', 'user_security_review') || $this->matchesSegments($segments, 'user', 'password-reset') || $this->matchesSegments($segments, 'user', 'reset-password') || $this->matchesSegments($segments, 'user', 'security-review') => RequestIntent::PasswordReset,
            $this->routeContains($route, 'contact') || $this->matchesSegments($segments, 'contact') => RequestIntent::Contact,
            $this->routeContains($route, 'captcha_refresh') || ($this->matchesSegments($segments, 'captcha') && $this->matches($path, $route, 'refresh')) => RequestIntent::CaptchaRefresh,
            $this->routeContains($route, 'captcha_failure') || ($this->matchesSegments($segments, 'captcha') && $this->matches($path, $route, 'failure')) => RequestIntent::CaptchaFailure,
            $this->matches($path, $route, 'upload', 'archive', 'media') && !$this->safeMethod($method) => RequestIntent::UploadArchiveValidation,
            $this->matches($path, $route, 'export', 'download') => RequestIntent::ExportDownload,
            $this->matches($path, $route, 'import') => RequestIntent::ImportOperation,
            $this->matches($path, $route, 'backup', 'restore') => RequestIntent::BackupRestore,
            $this->matches($path, $route, 'diagnostic', 'support') => RequestIntent::DiagnosticsSupport,
            !$this->safeMethod($method) => RequestIntent::FormSubmit,
            default => RequestIntent::BrowserNavigation,
        };
    }

    private function adminMutationIntent(string $path, string $route): RequestIntent
    {
        return match (true) {
            $this->matches($path, $route, 'settings') => RequestIntent::SettingsMutation,
            $this->matches($path, $route, 'users', 'acl') => RequestIntent::UserAclMutation,
            $this->matches($path, $route, 'packages') => RequestIntent::PackageAdminOperation,
            $this->matches($path, $route, 'upload', 'archive', 'media') => RequestIntent::UploadArchiveValidation,
            $this->matches($path, $route, 'export', 'download') => RequestIntent::ExportDownload,
            $this->matches($path, $route, 'import') => RequestIntent::ImportOperation,
            $this->matches($path, $route, 'backup', 'restore') => RequestIntent::BackupRestore,
            $this->matches($path, $route, 'diagnostic', 'support') => RequestIntent::DiagnosticsSupport,
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

    private function matches(string $path, string $route, string ...$needles): bool
    {
        $haystack = strtolower($path.' '.$route);

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function routeIs(string $route, string ...$routes): bool
    {
        return in_array($route, $routes, true);
    }

    private function routeContains(string $route, string $needle): bool
    {
        return str_contains(strtolower($route), strtolower($needle));
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

        if (null !== $this->languageCatalog && '' !== $firstSegment && in_array($firstSegment, $this->languageCatalog->availableLanguages(), true)) {
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
     * @param list<string> $segments
     */
    private function hasLocalizedReservedPath(array $segments): bool
    {
        return in_array($segments[1] ?? '', ['admin', 'api', 'captcha', 'contact', 'cron', 'editor', 'setup', 'user'], true);
    }
}
