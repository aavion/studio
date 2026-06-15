<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiFeaturePolicy;
use App\Core\Access\AccessActor;
use App\Core\Id\UuidFactory;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\UiAlertTranslation;
use App\View\Http\HttpErrorRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class UserApiKeyController extends AbstractController
{
    public function __construct(
        private readonly HttpErrorRenderer $httpError,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly ApiKeyVault $apiKeyVault,
        private readonly UuidFactory $uuidFactory,
        private readonly ApiFeaturePolicy $apiFeaturePolicy,
        private readonly UiAlertDispatcherInterface $alerts,
    ) {
    }

    #[Route('/user/api-keys', name: 'user_api_keys', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $user = $this->currentUser();

        if (!$user instanceof UserAccount) {
            return $this->httpError->unauthorized($request);
        }

        if (!$this->apiFeaturePolicy->canManageKeys($user)) {
            return $this->httpError->notFound($request);
        }

        $newPlainKey = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_api_key_create', $this->stringField($request, '_csrf_token'))) {
                $this->alertKey('error', 'ui.user.api_keys.errors.invalid_csrf');
            } else {
                $prefix = $this->stringField($request, 'prefix');
                $plainKey = $this->apiKeyVault->generatePlainKey($prefix);
                $status = '1' === $this->stringField($request, 'read_only') ? ApiKeyStatus::ReadOnly : ApiKeyStatus::ReadWrite;

                try {
                    $apiKey = new ApiKey($this->uuidFactory->generate(), $prefix, $this->apiKeyVault->hmac($plainKey), $this->apiKeyVault->encrypt($plainKey, $prefix), $user, $status);
                    $this->entityManager->persist($apiKey);
                    $this->entityManager->flush();
                    $this->audit($user, 'api_key.created', ['api_key_uid' => $apiKey->uid(), 'prefix' => $apiKey->prefix(), 'status' => $status->value]);
                    $newPlainKey = $plainKey;
                } catch (Throwable) {
                    $this->alertKey('error', 'ui.user.api_keys.errors.create_failed');
                }
            }
        }

        return $this->render('@frontend/user/api-keys.html.twig', [
            'api_keys' => $this->entityManager->getRepository(ApiKey::class)->findBy(
                ['user' => $user],
                ['createdAt' => 'DESC', 'prefix' => 'ASC'],
            ),
            'new_plain_api_key' => $newPlainKey,
        ]);
    }

    #[Route('/user/api-keys/{uid}/reveal', name: 'user_api_key_reveal', requirements: ['uid' => '[a-f0-9-]{36}'], methods: ['GET', 'POST'])]
    public function reveal(Request $request, string $uid): Response
    {
        $user = $this->currentUser();

        if (!$user instanceof UserAccount) {
            return $this->httpError->unauthorized($request);
        }

        if (!$this->apiFeaturePolicy->canManageKeys($user)) {
            return $this->httpError->notFound($request);
        }

        $apiKey = $this->entityManager->find(ApiKey::class, $uid);

        if (!$apiKey instanceof ApiKey || $apiKey->user() !== $user || !$this->isRevealable($apiKey)) {
            return $this->httpError->notFound($request);
        }

        $plainKey = null;
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_api_key_reveal_'.$uid, $this->stringField($request, '_csrf_token'))) {
                $errors[] = 'ui.user.api_keys.errors.invalid_csrf';
            }

            if (!$this->passwordHasher->isPasswordValid($user, $this->stringField($request, 'password'))) {
                $errors[] = 'ui.user.api_keys.errors.password';
            }

            if ([] === $errors && !$this->isRevealable($apiKey)) {
                return $this->httpError->notFound($request);
            }

            if ([] === $errors) {
                $plainKey = $this->apiKeyVault->decrypt($apiKey->encryptedKey(), $apiKey->prefix());
                $this->audit($user, 'api_key.revealed', ['api_key_uid' => $apiKey->uid(), 'prefix' => $apiKey->prefix()]);

                if (null === $plainKey) {
                    $errors[] = 'ui.user.api_keys.errors.decrypt_failed';
                }
            }
        }

        return $this->render('@frontend/user/api-key-reveal.html.twig', [
            'api_key' => $apiKey,
            'plain_api_key' => $plainKey,
            'errors' => $errors,
        ]);
    }

    #[Route('/user/api-keys/{uid}/revoke', name: 'user_api_key_revoke', requirements: ['uid' => '[a-f0-9-]{36}'], methods: ['POST'])]
    public function revoke(Request $request, string $uid): Response
    {
        $user = $this->currentUser();

        if (!$user instanceof UserAccount) {
            return $this->httpError->unauthorized($request);
        }

        if (!$this->apiFeaturePolicy->canManageKeys($user)) {
            return $this->httpError->notFound($request);
        }

        $apiKey = $this->entityManager->find(ApiKey::class, $uid);

        if ($apiKey instanceof ApiKey && $apiKey->user() === $user && $this->isRevealable($apiKey) && $this->isCsrfTokenValid('user_api_key_revoke_'.$uid, $this->stringField($request, '_csrf_token'))) {
            $apiKey->revoke();
            $this->entityManager->flush();
            $this->audit($user, 'api_key.revoked', ['api_key_uid' => $apiKey->uid(), 'prefix' => $apiKey->prefix()]);
        }

        return $this->redirectToRoute('user_api_keys');
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

    private function isRevealable(ApiKey $apiKey): bool
    {
        return in_array($apiKey->status(), [ApiKeyStatus::ReadOnly, ApiKeyStatus::ReadWrite], true);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(UserAccount $user, string $action, array $context): void
    {
        try {
            $this->auditLogger->log(AccessActor::fromUserAccount($user), $action, $context);
        } catch (Throwable) {
            return;
        }
    }

    private function alertKey(string $level, string $key): void
    {
        $this->alerts->addAlert(UiAlertTranslation::forLevel($level, $key), UiAlertDelivery::Direct);
    }

}
