<?php

declare(strict_types=1);

namespace App\Controller;

use App\Privacy\Cookie\CookieConsentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CookieConsentController extends AbstractController
{
    public function __construct(private readonly CookieConsentManager $consent)
    {
    }

    #[Route('/privacy/cookie-consent', name: 'privacy_cookie_consent', methods: ['POST'])]
    public function store(Request $request): Response
    {
        if (!$this->consent->validCsrfToken($request, (string) $request->request->get('_csrf_token', ''))) {
            return $this->redirectBack($request);
        }

        $accepted = [];
        if ('reject_optional' !== (string) $request->request->get('_cookie_consent_action', 'save_selection')) {
            $accepted = $request->request->all('cookies');
            $accepted = is_array($accepted)
                ? array_values(array_filter(array_map('strval', $accepted), 'strlen'))
                : [];
        }

        $response = $this->redirectBack($request);
        $this->consent->attachConsentCookie($request, $response, $accepted);

        return $response;
    }

    private function redirectBack(Request $request): RedirectResponse
    {
        $target = (string) $request->request->get('_cookie_consent_target_path', '');
        if ('' === $target || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
            $target = '/';
        }

        return new RedirectResponse($target);
    }
}
