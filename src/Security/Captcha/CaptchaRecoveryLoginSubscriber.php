<?php

declare(strict_types=1);

namespace App\Security\Captcha;

use App\Security\AutoBan\AutoBanRequestSubscriber;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class CaptchaRecoveryLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CaptchaFormValidator $captchaForms,
        private CsrfTokenManagerInterface $csrfTokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 15],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $event->hasResponse() || !$this->isRecoveryLoginSubmission($request)) {
            return;
        }

        if ($this->captchaForms->acceptsRequired($request)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('user_login', [
            'bypass' => '1',
            'captcha' => 'failed',
        ]), 303));
    }

    private function isRecoveryLoginSubmission(Request $request): bool
    {
        if ('POST' !== strtoupper($request->getMethod())) {
            return false;
        }

        $route = $request->attributes->get('_route');
        if ('user_login' !== $route && '/user/login' !== $request->getPathInfo()) {
            return false;
        }

        $token = $request->request->all()[AutoBanRequestSubscriber::RECOVERY_LOGIN_TOKEN_FIELD] ?? null;

        return is_string($token)
            && '' !== $token
            && $this->csrfTokens->isTokenValid(new CsrfToken(AutoBanRequestSubscriber::RECOVERY_LOGIN_TOKEN_ID, $token));
    }
}
