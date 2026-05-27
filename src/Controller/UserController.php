<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\View\Http\HttpErrorRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class UserController extends AbstractController
{
    public function __construct(
        private readonly HttpErrorRenderer $httpError,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/user', name: 'user_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->currentUser() instanceof UserAccount) {
            return $this->httpError->unauthorized($request);
        }

        return $this->redirectToRoute('user_profile');
    }

    #[Route('/user/profile', name: 'user_profile', methods: ['GET'])]
    public function profile(Request $request): Response
    {
        $user = $this->currentUser();

        if (!$user instanceof UserAccount) {
            return $this->httpError->unauthorized($request);
        }

        return $this->render('@frontend/user/profile.html.twig', [
            'user_account' => $user,
        ]);
    }

    #[Route('/user/password', name: 'user_password', methods: ['GET', 'POST'])]
    public function password(Request $request): Response
    {
        $user = $this->currentUser();

        if (!$user instanceof UserAccount) {
            return $this->httpError->unauthorized($request);
        }

        $errors = [];
        $success = false;

        if ($request->isMethod('POST')) {
            $currentPassword = $this->stringField($request, 'current_password');
            $newPassword = $this->stringField($request, 'new_password');
            $confirmPassword = $this->stringField($request, 'confirm_password');

            if (!$this->isCsrfTokenValid('user_password_change', $this->stringField($request, '_csrf_token'))) {
                $errors[] = 'ui.user.password.errors.invalid_csrf';
            }

            if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                $errors[] = 'ui.user.password.errors.current_password';
            }

            if (12 > strlen($newPassword)) {
                $errors[] = 'ui.user.password.errors.new_password_length';
            }

            if ($newPassword !== $confirmPassword) {
                $errors[] = 'ui.user.password.errors.password_mismatch';
            }

            if ([] === $errors) {
                $user->changePassword($this->passwordHasher->hashPassword($user, $newPassword));
                $this->entityManager->flush();
                $success = true;
            }
        }

        return $this->render('@frontend/user/password.html.twig', [
            'errors' => $errors,
            'success' => $success,
        ]);
    }

    #[Route('/user/api-keys', name: 'user_api_keys', methods: ['GET'])]
    public function apiKeys(Request $request): Response
    {
        $user = $this->currentUser();

        if (!$user instanceof UserAccount) {
            return $this->httpError->unauthorized($request);
        }

        return $this->render('@frontend/user/api-keys.html.twig', [
            'api_keys' => $this->entityManager->getRepository(ApiKey::class)->findBy(
                ['user' => $user],
                ['createdAt' => 'DESC', 'prefix' => 'ASC'],
            ),
        ]);
    }

    #[Route('/user/invitation/{token}', name: 'user_invitation_accept', methods: ['GET'])]
    public function invitation(string $token): Response
    {
        return $this->render('@frontend/user/invitation.html.twig', [
            'invitation_token' => $token,
        ]);
    }

    private function currentUser(): ?UserAccount
    {
        $user = $this->getUser();

        return $user instanceof UserAccount ? $user : null;
    }

    private function stringField(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return is_string($value) ? $value : '';
    }
}
