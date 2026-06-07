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
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Entity\UserAccount;
use App\Security\AdminUserAccountUpdateService;
use App\Security\DeletedUserCleanup;
use App\Security\UserAccountStatus;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class UserApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private UserApiReadModel $readModel,
        private EntityManagerInterface $entityManager,
        private AdminUserAccountUpdateService $updateService,
        private ApiJsonRequestParser $jsonRequests,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return UserApiEndpointProvider::HANDLER_USERS_INDEX;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        $requestedUsername = $this->usernameFromPath($request->getPathInfo());
        $user = null === $requestedUsername ? null : $this->user($requestedUsername);
        if (null !== $requestedUsername && null === $user) {
            return $this->notFound($request, $requestedUsername);
        }

        if (null !== $user && $request->isMethod(Request::METHOD_PATCH)) {
            return $this->updateUser($request, $user);
        }

        if (null !== $user) {
            return $this->responder->data($this->readModel->resource($user, includeUid: true));
        }

        $view = $this->readModel->users($request);

        return $this->responder->data($view['data'], meta: $view['meta']);
    }

    private function updateUser(Request $request, UserAccount $user): Response
    {
        try {
            $payload = $this->jsonRequests->object($request);
        } catch (JsonException $error) {
            return $this->invalidRequest($request, $error->getMessage());
        }

        $status = $this->status($payload['status'] ?? $user->status()->value);
        $role = $this->role($payload['role'] ?? $user->role()->value);
        $groups = $this->groups($payload['groups'] ?? $this->groupIdentifiers($user));
        $errors = [];

        if (!$status instanceof UserAccountStatus) {
            $errors['status'] = ['admin.users.form.errors.invalid_status'];
        }

        if (!$role instanceof UserRole || UserRole::Public === $role) {
            $errors['role'] = ['admin.users.form.errors.invalid_role'];
        }

        if (null === $groups) {
            $errors['groups'] = ['admin.users.form.errors.group_invalid'];
        }

        if ([] !== $errors) {
            return $this->validationFailed($request, $errors, ['target_user' => $user->username()]);
        }

        $context = ApiRequestContext::fromRequest($request);
        $actor = $context?->actor();
        if (null === $actor) {
            return $this->validationFailed($request, ['__actor' => ['admin.users.form.errors.invalid']], ['target_user' => $user->username()]);
        }

        $result = $this->updateService->update($actor, $actor->username(), $user, $status, $role, $groups);
        if ('error' === $result->flashLevel()) {
            return $this->validationFailed($request, ['__form' => [$result->flashKey()]], $result->auditContext());
        }

        return $this->responder->data($this->readModel->resource($user, includeUid: true), meta: [
            'audit_action' => $result->auditAction(),
            'audit_context' => $result->auditContext(),
        ]);
    }

    private function usernameFromPath(string $path): ?string
    {
        if (1 !== preg_match('#^/api/v1/admin/users/items/([A-Za-z][A-Za-z0-9_-]{4,29})$#', $path, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function user(string $username): ?UserAccount
    {
        $user = $this->entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $username]);
        if (
            !$user instanceof UserAccount
            || DeletedUserCleanup::DELETED_USER_UID === $user->uid()
            || UserAccountStatus::Deleted === $user->status()
        ) {
            return null;
        }

        return $user;
    }

    private function status(mixed $status): ?UserAccountStatus
    {
        return is_string($status) ? UserAccountStatus::tryFrom($status) : null;
    }

    private function role(mixed $role): ?UserRole
    {
        return is_string($role) ? UserRole::tryFrom($role) : null;
    }

    /**
     * @return list<string>|null
     */
    private function groups(mixed $groups): ?array
    {
        if (!is_array($groups)) {
            return null;
        }

        $identifiers = [];
        foreach ($groups as $group) {
            if (!is_string($group) || '' === trim($group)) {
                return null;
            }

            $identifiers[] = trim($group);
        }

        return array_values(array_unique($identifiers));
    }

    /**
     * @return list<string>
     */
    private function groupIdentifiers(UserAccount $user): array
    {
        $identifiers = [];

        foreach ($user->groups() as $group) {
            $identifier = method_exists($group, 'identifier') ? $group->identifier() : null;
            if (is_string($identifier)) {
                $identifiers[] = $identifier;
            }
        }

        sort($identifiers);

        return array_values(array_unique($identifiers));
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

    private function notFound(Request $request, string $username): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_ENDPOINT_NOT_FOUND, ApiMessageKey::API_ENDPOINT_NOT_FOUND, context: [
                'path' => $request->getPathInfo(),
                'username' => $username,
            ]),
            Response::HTTP_NOT_FOUND,
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
}
