<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiJsonRequestParser;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Core\Id\UuidFactory;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Message\MessageException;
use App\Core\Validation\EmailAddress;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Localization\UserProfileLocaleService;
use App\Security\AccountTokenMaintenance;
use App\Security\AccountTokenType;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use App\Security\UserFlowConfig;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class SelfServiceApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private SelfServiceApiReadModel $readModel,
        private EntityManagerInterface $entityManager,
        private UserFlowConfig $userFlowConfig,
        private UserProfileLocaleService $profileLocales,
        private AccountTokenMaintenance $tokenMaintenance,
        private ApiKeyVault $apiKeyVault,
        private UuidFactory $uuidFactory,
        private ApiJsonRequestParser $jsonRequests,
        private AuditLoggerInterface $auditLogger,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return SelfServiceApiEndpointProvider::HANDLER_USER_SELF;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::USER);
        if (null !== $denied) {
            return $denied;
        }

        $user = ApiRequestContext::fromRequest($request)?->user();
        if (!$user instanceof UserAccount) {
            return $this->notFound($request);
        }

        if (str_starts_with($request->getPathInfo(), '/api/v1/user/api-keys')) {
            return $this->handleApiKeys($request, $user);
        }

        if ($request->isMethod(Request::METHOD_PATCH)) {
            return $this->updateProfile($request, $user);
        }

        return $this->responder->data($this->readModel->user($user));
    }

    private function handleApiKeys(Request $request, UserAccount $user): Response
    {
        $keyUid = $this->keyUidFromPath($request->getPathInfo());
        if (null !== $keyUid) {
            return $this->revokeApiKey($request, $user, $keyUid);
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            return $this->createApiKey($request, $user);
        }

        $keys = $this->entityManager->getRepository(ApiKey::class)->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC', 'prefix' => 'ASC'],
        );

        return $this->responder->data(array_map(
            fn (ApiKey $apiKey): array => $this->readModel->apiKey($apiKey),
            $keys,
        ), meta: ['count' => count($keys)]);
    }

    private function updateProfile(Request $request, UserAccount $user): Response
    {
        try {
            $payload = $this->jsonRequests->object($request);
        } catch (JsonException $error) {
            return $this->invalidRequest($request, $error->getMessage());
        }

        $errors = $this->profileErrors($payload, $user);
        if ([] !== $errors) {
            return $this->validationFailed($request, $errors);
        }

        $updated = [];
        if (array_key_exists('username', $payload)) {
            $user->changeUsername($this->string($payload['username']));
            $updated[] = 'username';
        }

        if (array_key_exists('email', $payload)) {
            $email = EmailAddress::normalize($this->string($payload['email']));
            if ($email !== $user->email()) {
                $this->tokenMaintenance->revokePendingForUser($user, [AccountTokenType::PasswordReset, AccountTokenType::SecurityReview]);
            }

            $user->changeEmail($email);
            $updated[] = 'email';
        }

        if (array_key_exists('display_name', $payload)) {
            $user->updateProfile([
                ...$user->profile(),
                'display_name' => $this->string($payload['display_name']),
            ]);
            $updated[] = 'display_name';
        }

        if (array_key_exists('language', $payload)) {
            $language = $this->string($payload['language']) ?: 'default';
            $user->updateSettings([
                ...$user->settings(),
                'language' => $language,
            ]);
            $updated[] = 'language';
        }

        try {
            $this->entityManager->flush();
            $this->profileLocales->apply($request, $user);
            $this->audit($request, 'user.profile_updated', ['updated_fields' => $updated]);
        } catch (MessageException $exception) {
            return $this->validationFailed($request, ['__form' => [$exception->messageKey()]]);
        }

        return $this->responder->data($this->readModel->user($user), meta: [
            'updated_fields' => array_values(array_unique($updated)),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, list<string>>
     */
    private function profileErrors(array $payload, UserAccount $user): array
    {
        $errors = [];
        $allowed = ['username', 'email', 'display_name', 'language'];
        $unknown = array_values(array_diff(array_keys($payload), $allowed));
        if ([] !== $unknown) {
            $errors['__unknown'] = $unknown;
        }

        if (array_key_exists('username', $payload)) {
            $username = $this->string($payload['username']);
            if (!$this->userFlowConfig->usernameChangeEnabled()) {
                $errors['username'] = ['ui.user.profile.errors.username_change_disabled'];
            } elseif (!UserAccount::isValidUsername($username)) {
                $errors['username'] = ['ui.user.profile.errors.username_invalid'];
            } else {
                $existing = $this->entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $username]);
                if ($existing instanceof UserAccount && $existing !== $user) {
                    $errors['username'] = ['ui.user.profile.errors.username_in_use'];
                }
            }
        }

        if (array_key_exists('email', $payload)) {
            $email = EmailAddress::normalize($this->string($payload['email']));
            if (!EmailAddress::isValid($email)) {
                $errors['email'] = ['ui.user.profile.errors.email_invalid'];
            } else {
                $existing = $this->entityManager->getRepository(UserAccount::class)->findOneBy(['email' => $email]);
                if ($existing instanceof UserAccount && $existing !== $user) {
                    $errors['email'] = ['ui.user.profile.errors.email_in_use'];
                }
            }
        }

        if (array_key_exists('language', $payload)) {
            $language = $this->string($payload['language']) ?: 'default';
            if ('default' !== $language && !in_array($language, $this->profileLocales->availableLocales(), true)) {
                $errors['language'] = ['ui.user.profile.errors.language_invalid'];
            }
        }

        return $errors;
    }

    private function createApiKey(Request $request, UserAccount $user): Response
    {
        try {
            $payload = $this->jsonRequests->object($request);
        } catch (JsonException $error) {
            return $this->invalidRequest($request, $error->getMessage());
        }

        $prefix = $this->string($payload['prefix'] ?? null);
        $status = false === ($payload['read_only'] ?? true) ? ApiKeyStatus::ReadWrite : ApiKeyStatus::ReadOnly;
        $plainKey = $this->apiKeyVault->generatePlainKey($prefix);

        try {
            $apiKey = new ApiKey(
                $this->uuidFactory->generate(),
                $prefix,
                $this->apiKeyVault->hmac($plainKey),
                $this->apiKeyVault->encrypt($plainKey, $prefix),
                $user,
                $status,
            );
            $this->entityManager->persist($apiKey);
            $this->entityManager->flush();
            $this->audit($request, 'api_key.created', [
                'api_key_uid' => $apiKey->uid(),
                'prefix' => $apiKey->prefix(),
                'status' => $status->value,
            ]);

            return $this->responder->data(
                $this->readModel->apiKey($apiKey, includeUid: true, plainKey: $plainKey),
                Response::HTTP_CREATED,
            );
        } catch (Throwable $error) {
            return $this->validationFailed($request, ['prefix' => ['ui.user.api_keys.errors.create_failed']], [
                'exception' => $error::class,
            ]);
        }
    }

    private function revokeApiKey(Request $request, UserAccount $user, string $keyUid): Response
    {
        $apiKey = $this->entityManager->find(ApiKey::class, $keyUid);
        if (!$apiKey instanceof ApiKey || $apiKey->user() !== $user) {
            return $this->notFound($request);
        }

        if (ApiKeyStatus::Revoked !== $apiKey->status()) {
            $apiKey->revoke();
            $this->entityManager->flush();
            $this->audit($request, 'api_key.revoked', [
                'api_key_uid' => $apiKey->uid(),
                'prefix' => $apiKey->prefix(),
            ]);
        }

        return $this->responder->data($this->readModel->apiKey($apiKey, includeUid: true));
    }

    private function keyUidFromPath(string $path): ?string
    {
        if (1 !== preg_match('#^/api/v1/user/api-keys/items/([a-f0-9-]{36})$#', $path, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function notFound(Request $request): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_ENDPOINT_NOT_FOUND, ApiMessageKey::API_ENDPOINT_NOT_FOUND, context: [
                'path' => $request->getPathInfo(),
            ]),
            Response::HTTP_NOT_FOUND,
            $request,
        );
    }

    private function invalidRequest(Request $request, string $reason): Response
    {
        return $this->responder->error(
            Message::warning(CommonMessageCode::E_INVALID_ARGUMENT, ApiMessageKey::API_REQUEST_INVALID, context: [
                'path' => $request->getPathInfo(),
                'reason' => $reason,
            ]),
            Response::HTTP_BAD_REQUEST,
            $request,
        );
    }

    /**
     * @param array<string, mixed> $errors
     * @param array<string, mixed> $context
     */
    private function validationFailed(Request $request, array $errors, array $context = []): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_VALIDATION_FAILED, ApiMessageKey::API_VALIDATION_FAILED, context: [
                ...$context,
                'path' => $request->getPathInfo(),
                'errors' => $errors,
            ]),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $request,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(Request $request, string $action, array $context): void
    {
        $actor = ApiRequestContext::fromRequest($request)?->actor();
        if (null === $actor) {
            return;
        }

        try {
            $this->auditLogger->log($actor, $action, $context);
        } catch (Throwable) {
            return;
        }
    }
}
