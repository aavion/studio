<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\UserFlowConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    public function __construct(
        private readonly UserFlowConfig $config,
    ) {
    }

    #[Route('/user/login', name: 'user_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, Request $request): Response
    {
        return $this->render('@frontend/user/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'authentication_error' => null !== $authenticationUtils->getLastAuthenticationError(),
            'return_to' => $this->returnTo($request),
            'registration_enabled' => $this->config->registrationEnabled(),
            'account_closed' => '1' === $request->query->get('account_closed'),
        ]);
    }

    #[Route('/user/logout', name: 'user_logout', methods: ['GET'])]
    public function logout(): Response
    {
        return $this->render('@frontend/user/logout.html.twig');
    }

    #[Route('/user/logout', name: 'user_logout_perform', methods: ['POST'])]
    public function performLogout(): Response
    {
        return $this->redirectToRoute('user_login');
    }

    private function returnTo(Request $request): ?string
    {
        $returnTo = $request->query->get('return_to');

        return is_string($returnTo) && $this->isSafeLocalTarget($returnTo) ? $returnTo : null;
    }

    private function isSafeLocalTarget(string $target): bool
    {
        return '' !== $target
            && str_starts_with($target, '/')
            && !str_starts_with($target, '//')
            && !str_contains($target, '\\')
            && 1 !== preg_match('/[\x00-\x1F\x7F]/', $target);
    }
}
