<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiJsonRequestParser;
use App\Api\Http\ApiListQueryNormalizer;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Core\Id\UuidFactory;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Operation\Live\LiveOperationStarter;
use App\Entity\AclGroup;
use App\Security\AclGroupImpactService;
use App\Security\AdminUserAccessPolicy;
use App\Security\AdminUserListViewFactory;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class UserGroupApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private AdminUserListViewFactory $lists,
        private EntityManagerInterface $entityManager,
        private AdminUserAccessPolicy $policy,
        private AclGroupImpactService $impact,
        private UserGroupApiReadModel $readModel,
        private LiveOperationStarter $liveOperations,
        private UuidFactory $uuidFactory,
        private ApiJsonRequestParser $jsonRequests,
        private ApiListQueryNormalizer $listQueries,
        private AuditLoggerInterface $auditLogger,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return UserApiEndpointProvider::HANDLER_USER_GROUPS_INDEX;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        $groupIdentifier = $this->groupIdentifierFromPath($request->getPathInfo());
        if (null !== $groupIdentifier) {
            $group = $this->group($groupIdentifier);
            if (!$group instanceof AclGroup) {
                return $this->notFound($request, ['group_identifier' => $groupIdentifier]);
            }

            return match ($request->getMethod()) {
                Request::METHOD_PATCH => $this->updateGroup($request, $group),
                Request::METHOD_DELETE => $this->deleteGroup($request, $group),
                default => $this->responder->data($this->readModel->resource($group, includeDetail: true)),
            };
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            return $this->createGroup($request);
        }

        $view = $this->lists->groupsView($this->listQueries->backendRequest($request));
        $groups = array_map($this->readModel->resource(...), $view['items']);

        unset($view['items']);

        return $this->responder->data($groups, meta: $this->listQueries->apiMeta($view));
    }

    private function createGroup(Request $request): Response
    {
        try {
            $payload = $this->jsonRequests->object($request);
        } catch (JsonException $error) {
            return $this->invalidRequest($request, $error->getMessage());
        }

        $identifier = $this->string($payload['identifier'] ?? null);
        $name = $this->string($payload['name'] ?? null);
        $minRole = $this->int($payload['min_role'] ?? null);
        $errors = [];

        if ('' === $identifier) {
            $errors['identifier'] = ['admin.groups.form.invalid'];
        }

        if ('' === $name) {
            $errors['name'] = ['admin.groups.form.invalid'];
        }

        if (null === $minRole) {
            $errors['min_role'] = ['admin.groups.form.invalid'];
        }

        $actor = ApiRequestContext::fromRequest($request)?->actor();
        if (null === $actor) {
            $errors['__actor'] = ['admin.users.form.errors.invalid'];
        } elseif (null !== $minRole && null !== $this->policy->validateGroupCreate($actor, $minRole)) {
            $errors['min_role'] = ['admin.groups.form.higher_access'];
        }

        if ($this->entityManager->getRepository(AclGroup::class)->findOneBy(['identifier' => $identifier]) instanceof AclGroup) {
            $errors['identifier'] = ['admin.groups.form.invalid'];
        }

        if ([] !== $errors) {
            return $this->validationFailed($request, $errors);
        }

        try {
            $group = new AclGroup($this->uuidFactory->generate(), $identifier, $name, (int) $minRole);
            $this->entityManager->persist($group);
            $this->entityManager->flush();
            $this->audit($request, 'acl.group_created', ['group' => $group->identifier()]);

            return $this->responder->data($this->readModel->resource($group, includeDetail: true), Response::HTTP_CREATED);
        } catch (Throwable $error) {
            return $this->validationFailed($request, ['__form' => ['admin.groups.form.invalid']], [
                'exception' => $error::class,
            ]);
        }
    }

    private function updateGroup(Request $request, AclGroup $group): Response
    {
        try {
            $payload = $this->jsonRequests->object($request);
        } catch (JsonException $error) {
            return $this->invalidRequest($request, $error->getMessage());
        }

        $pending = [
            'name' => $this->string($payload['name'] ?? $group->name()),
            'min_role' => $this->int($payload['min_role'] ?? $group->minRole()),
        ];
        $errors = $this->groupUpdateErrors($request, $group, $pending);
        if ([] !== $errors) {
            return $this->validationFailed($request, $errors, ['group_identifier' => $group->identifier()]);
        }

        $impact = $this->impact->impact($group);
        if (!$request->query->getBoolean('confirm')) {
            return $this->groupReview($group, 'update', $impact, $pending);
        }

        if (true === ($payload['live_operation'] ?? false)) {
            return $this->startGroupOperation($request, $group, 'update', $pending);
        }

        $oldName = $group->name();
        $oldMinRole = $group->minRole();
        $group->rename($pending['name']);
        $floorCleanup = $this->impact->removeBelowMinRoleReferences($group, $pending['min_role']);
        $group->changeMinRole($pending['min_role']);
        $this->entityManager->flush();
        $this->audit($request, 'acl.group_updated', [
            'group' => $group->identifier(),
            'old_name' => $oldName,
            'new_name' => $group->name(),
            'old_min_role' => $oldMinRole,
            'new_min_role' => $group->minRole(),
            'impact' => $impact['summary'],
            'floor_cleanup' => $floorCleanup,
        ]);

        return $this->responder->data($this->readModel->resource($group, includeDetail: true), meta: [
            'impact' => $impact['summary'],
            'floor_cleanup' => $floorCleanup,
        ]);
    }

    private function deleteGroup(Request $request, AclGroup $group): Response
    {
        try {
            $payload = $this->jsonRequests->object($request);
        } catch (JsonException $error) {
            return $this->invalidRequest($request, $error->getMessage());
        }

        $actor = ApiRequestContext::fromRequest($request)?->actor();
        $policyError = null === $actor ? 'admin.users.form.errors.invalid' : $this->policy->validateGroupDelete($actor, $group);
        if (null !== $policyError) {
            return $this->validationFailed($request, ['__form' => [$policyError]], ['group_identifier' => $group->identifier()]);
        }

        $impact = $this->impact->impact($group);
        if (!$request->query->getBoolean('confirm')) {
            return $this->groupReview($group, 'delete', $impact);
        }

        if (true === ($payload['live_operation'] ?? false)) {
            return $this->startGroupOperation($request, $group, 'delete');
        }

        $cleanupImpact = $this->impact->removeReferences($group);
        $identifier = $group->identifier();
        $this->entityManager->remove($group);
        $this->entityManager->flush();
        $this->audit($request, 'acl.group_deleted', [
            'group' => $identifier,
            'impact' => $cleanupImpact['summary'],
        ]);

        return $this->responder->data([
            'type' => 'acl_group_delete_result',
            'id' => $identifier,
            'attributes' => [
                'status' => 'deleted',
                'group' => $identifier,
                'impact' => $cleanupImpact['summary'],
            ],
            'links' => ['groups' => '/api/v1/admin/users/groups'],
        ]);
    }

    /**
     * @param array{name: string, min_role: int|null} $pending
     *
     * @return array<string, list<string>>
     */
    private function groupUpdateErrors(Request $request, AclGroup $group, array $pending): array
    {
        $errors = [];
        if ('' === $pending['name']) {
            $errors['name'] = ['admin.groups.form.invalid'];
        }

        if (null === $pending['min_role']) {
            $errors['min_role'] = ['admin.groups.form.invalid'];
        }

        $actor = ApiRequestContext::fromRequest($request)?->actor();
        $policyError = null === $actor || null === $pending['min_role']
            ? 'admin.groups.form.invalid'
            : $this->policy->validateGroupUpdate($actor, $group, $pending['min_role']);
        if (null !== $policyError) {
            $errors['__form'] = [$policyError];
        }

        return $errors;
    }

    /**
     * @param array<string, mixed>      $impact
     * @param array<string, mixed>|null $pending
     */
    private function groupReview(AclGroup $group, string $operation, array $impact, ?array $pending = null): Response
    {
        return $this->responder->data(
            $this->readModel->review($group, $operation, $impact, $pending),
            links: $this->readModel->reviewLinks($group),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function startGroupOperation(Request $request, AclGroup $group, string $action, array $payload = []): Response
    {
        $actorUid = ApiRequestContext::fromRequest($request)?->actor()->userUid();
        if (!is_string($actorUid) || '' === $actorUid) {
            return $this->validationFailed($request, ['__actor' => ['admin.users.form.errors.invalid']], ['group_identifier' => $group->identifier()]);
        }

        $result = $this->liveOperations->start(
            LiveOperationQueueFactory::ACL_GROUP_APPLY,
            [
                'group_uid' => $group->uid(),
                'action' => $action,
                'payload' => $payload,
                'actor_uid' => $actorUid,
                'trigger' => 'api',
            ],
            sprintf('ACL group %s %s', $group->identifier(), $action),
        );
        if (!$result->isSuccess() || !is_array($result->value())) {
            return $this->validationFailed($request, ['__operation' => [$result->firstIssue()?->translationKey() ?? 'message.operation.failed']], [
                'group_identifier' => $group->identifier(),
                'action' => $action,
            ]);
        }

        $operationId = (string) ($result->value()['operation_id'] ?? '');

        return $this->responder->data([
            'type' => 'operation_start',
            'id' => $operationId,
            'attributes' => $result->value(),
            'links' => $this->operationLinks($operationId),
        ], Response::HTTP_ACCEPTED, links: $this->operationLinks($operationId));
    }

    /**
     * @return array<string, string>
     */
    private function operationLinks(string $operationId): array
    {
        return [
            'status' => '/api/v1/admin/operations/'.$operationId,
            'continue' => '/api/v1/admin/operations/'.$operationId.'/continue',
        ];
    }

    private function groupIdentifierFromPath(string $path): ?string
    {
        if (1 !== preg_match('#^/api/v1/admin/users/groups/items/([a-z][a-z0-9_]{2,79})$#', $path, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function group(string $identifier): ?AclGroup
    {
        $group = $this->entityManager->getRepository(AclGroup::class)->findOneBy(['identifier' => $identifier]);

        return $group instanceof AclGroup ? $group : null;
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

    private function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function int(mixed $value): ?int
    {
        try {
            if (is_int($value)) {
                return AccessLevel::assert($value);
            }

            if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
                return AccessLevel::assert((int) $value);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
