<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Message\Message;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use App\Security\AdminUserAccessPolicy;
use App\Security\UserGroupMembershipManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class UserGroupMembershipApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdminUserAccessPolicy $policy,
        private UserGroupMembershipManager $memberships,
        private UserApiReadModel $userReadModel,
        private AuditLoggerInterface $auditLogger,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return UserApiEndpointProvider::HANDLER_USER_GROUP_MEMBERSHIPS;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        $membership = $this->membershipFromPath($request->getPathInfo());
        if (null === $membership) {
            return $this->notFound($request, ['path' => $request->getPathInfo()]);
        }

        return $this->changeMembership($request, $membership['username'], $membership['group_identifier']);
    }

    private function changeMembership(Request $request, string $username, string $groupIdentifier): Response
    {
        $user = $this->entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $username]);
        $group = $this->entityManager->getRepository(AclGroup::class)->findOneBy(['identifier' => $groupIdentifier]);
        if (!$user instanceof UserAccount || !$group instanceof AclGroup) {
            return $this->notFound($request, ['username' => $username, 'group_identifier' => $groupIdentifier]);
        }

        $current = $this->memberships->identifiers($user);
        $next = Request::METHOD_POST === $request->getMethod()
            ? array_values(array_unique([...$current, $groupIdentifier]))
            : array_values(array_filter($current, static fn (string $identifier): bool => $identifier !== $groupIdentifier));

        $actor = ApiRequestContext::fromRequest($request)?->actor();
        $policyError = null === $actor
            ? 'admin.users.form.errors.invalid'
            : $this->policy->validateUserUpdate($actor, $user, $user->status(), $user->role(), $next);
        if (null !== $policyError) {
            return $this->validationFailed($request, ['groups' => [$policyError]], [
                'username' => $username,
                'group_identifier' => $groupIdentifier,
            ]);
        }

        $this->memberships->replaceExisting($user, $next);
        $this->entityManager->flush();
        $this->audit($request, Request::METHOD_POST === $request->getMethod() ? 'acl.user_group_added' : 'acl.user_group_removed', [
            'target_user' => $user->username(),
            'group' => $groupIdentifier,
        ]);

        return $this->responder->data($this->userReadModel->resource($user), meta: [
            'groups' => $next,
        ]);
    }

    /**
     * @return array{username: string, group_identifier: string}|null
     */
    private function membershipFromPath(string $path): ?array
    {
        if (1 !== preg_match('#^/api/v1/admin/users/items/([A-Za-z][A-Za-z0-9_-]{4,29})/groups/([a-z][a-z0-9_]{2,79})$#', $path, $matches)) {
            return null;
        }

        return ['username' => rawurldecode($matches[1]), 'group_identifier' => rawurldecode($matches[2])];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function notFound(Request $request, array $context): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_ENDPOINT_NOT_FOUND, ApiMessageKey::API_ENDPOINT_NOT_FOUND, context: [
                ...$context,
                'path' => $request->getPathInfo(),
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

    /**
     * @param array<string, mixed> $context
     */
    private function audit(Request $request, string $action, array $context): void
    {
        $actor = ApiRequestContext::fromRequest($request)?->actor();
        if (null === $actor) {
            return;
        }

        $this->auditLogger->log($actor, $action, [
            ...$context,
            'result_status' => 'success',
        ]);
    }
}
