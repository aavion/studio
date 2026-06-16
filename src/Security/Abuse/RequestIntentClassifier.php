<?php

declare(strict_types=1);

namespace App\Security\Abuse;

use Symfony\Component\HttpFoundation\Request;

final readonly class RequestIntentClassifier
{
    public function __construct(private SuspiciousProbePathMatcher $probePathMatcher = new SuspiciousProbePathMatcher())
    {
    }

    public function classify(Request $request): AbuseRequestProfile
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getPathInfo();
        $route = $this->route($request);
        $family = $this->family($path);
        $prefetch = $this->isPrefetch($request);
        $suspiciousProbe = $this->probePathMatcher->isProbe($path);

        return new AbuseRequestProfile(
            $family,
            $this->intent($request, $method, $path, $route, $family, $prefetch, $suspiciousProbe),
            $method,
            substr($path, 0, 1024),
            $route,
            $prefetch,
            $suspiciousProbe,
        );
    }

    private function family(string $path): RequestFamily
    {
        return match (true) {
            str_starts_with($path, '/api/live') => RequestFamily::LiveApi,
            str_starts_with($path, '/api') => RequestFamily::Api,
            str_starts_with($path, '/cron') => RequestFamily::Scheduler,
            str_starts_with($path, '/setup') => RequestFamily::Setup,
            str_starts_with($path, '/admin') => RequestFamily::Admin,
            str_starts_with($path, '/editor') => RequestFamily::Editor,
            default => RequestFamily::Browser,
        };
    }

    private function intent(
        Request $request,
        string $method,
        string $path,
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
            $this->matches($path, $route, 'login') => RequestIntent::Login,
            $this->matches($path, $route, 'registration', 'register') => RequestIntent::Registration,
            $this->matches($path, $route, 'password', 'recovery', 'reset') => RequestIntent::PasswordReset,
            $this->matches($path, $route, 'contact') => RequestIntent::Contact,
            $this->matches($path, $route, 'captcha', 'refresh') => RequestIntent::CaptchaRefresh,
            $this->matches($path, $route, 'captcha', 'failure') => RequestIntent::CaptchaFailure,
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
}
