<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Operation\Live\LiveOperationRunStore;
use App\Core\Operation\Live\LiveOperationStarter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AdminOperationApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private LiveOperationRunStore $operations,
        private LiveOperationStarter $starter,
        private LiveOperationApiResourceFactory $operationResources,
        private AuditLoggerInterface $auditLogger,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
        private AdminFeatureApiGuard $featureGuard,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return AdminOperationalApiEndpointProvider::HANDLER_OPERATIONS;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        if ($denied = $this->featureGuard->denyUnlessVisible($request, 'admin.operations', 'listAdminOperations')) {
            return $denied;
        }

        $maintenanceAction = $this->maintenanceActionFromPath($request->getPathInfo());
        if (null !== $maintenanceAction) {
            return $this->maintenance($request, $maintenanceAction);
        }

        $operationId = $this->operationIdFromPath($request->getPathInfo());
        if (null !== $operationId && str_ends_with($request->getPathInfo(), '/continue')) {
            return $this->continueOperation($request, $operationId);
        }

        if (null !== $operationId) {
            return $this->operation($request, $operationId);
        }

        $runs = array_map(static fn (array $run): array => [
            'type' => 'operation_run',
            'id' => (string) ($run['operation_id'] ?? ''),
            'attributes' => $run,
        ], $this->operations->summaries());

        return $this->responder->data($runs, meta: [
            'count' => count($runs),
            'runner_lock' => $this->operations->runnerLockStatus(3600),
        ]);
    }

    private function maintenance(Request $request, string $action): Response
    {
        if ($request->query->getBoolean('confirm')) {
            if ($denied = $this->featureGuard->denyUnlessMutable($request, 'admin.operations', 'runAdminOperationMaintenance')) {
                return $denied;
            }
        }

        if (!$request->query->getBoolean('confirm')) {
            return $this->responder->data([
                'type' => 'operation_maintenance_review',
                'id' => $action,
                'attributes' => [
                    'status' => 'requires_confirmation',
                    'action' => $action,
                    'ttl_seconds' => 3600,
                    'confirm_parameter' => 'confirm=true',
                    'impact' => $this->maintenanceImpact($action),
                ],
                'links' => $this->maintenanceLinks($action),
            ], links: $this->maintenanceLinks($action));
        }

        $result = match ($action) {
            'cleanup' => $this->operations->cleanup(3600),
            'clear-stale-lock' => [
                'cleared' => $this->operations->clearRunnerLock(staleOnly: true, ttlSeconds: 3600),
                'ttl_seconds' => 3600,
            ],
            'kill-stale-runner' => [
                ...$this->operations->killStaleRunner(3600),
                'ttl_seconds' => 3600,
            ],
            default => null,
        };

        if (null === $result) {
            return $this->notFound($request, $action);
        }

        $this->audit($request, 'operations.'.str_replace('-', '_', $action), $result);

        return $this->responder->data([
            'type' => 'operation_maintenance_result',
            'id' => $action,
            'attributes' => [
                'status' => 'completed',
                'action' => $action,
                'result' => $result,
            ],
            'links' => [
                'self' => '/api/v1/admin/operations/'.$action,
                'operations' => '/api/v1/admin/operations',
            ],
        ]);
    }

    private function operation(Request $request, string $operationId): Response
    {
        $report = $this->operations->report($operationId);
        if (null === $report) {
            return $this->notFound($request, $operationId);
        }

        return $this->responder->data([
            'type' => 'operation_run',
            'id' => $operationId,
            'attributes' => $report,
            'links' => $this->operationResources->links(
                $operationId,
                (bool) ($report['result']['can_continue'] ?? false),
            ),
        ], links: $this->operationResources->links(
            $operationId,
            (bool) ($report['result']['can_continue'] ?? false),
        ));
    }

    private function continueOperation(Request $request, string $operationId): Response
    {
        if ($request->query->getBoolean('confirm')) {
            if ($denied = $this->featureGuard->denyUnlessMutable($request, 'admin.operations', 'continueAdminOperation')) {
                return $denied;
            }
        }

        if (null === $this->operations->report($operationId)) {
            return $this->notFound($request, $operationId);
        }

        $continuation = $this->operations->continuationForOperator($operationId);
        if (null === $continuation) {
            return $this->operationUnavailable($request, 'continueAdminOperation', [
                'operation_id' => $operationId,
                'reason' => 'no_continuation',
            ]);
        }

        if (!$request->query->getBoolean('confirm')) {
            return $this->responder->data([
                'type' => 'operation_continuation',
                'id' => $operationId,
                'attributes' => [
                    'status' => 'requires_confirmation',
                    'operation' => $continuation['operation'],
                    'label' => $continuation['label'],
                    'confirm_parameter' => 'confirm=true',
                ],
                'links' => $this->operationResources->continuationLinks($operationId),
            ], status: Response::HTTP_OK, links: $this->operationResources->continuationLinks($operationId));
        }

        $result = $this->starter->start($continuation['operation'], $continuation['payload'], $continuation['label']);
        if (!$result->isSuccess() || !is_array($result->value())) {
            return $this->responder->error(
                $result->firstIssue() ?? Message::error(CommonMessageCode::E_OPERATION_FAILED, ApiMessageKey::API_OPERATION_UNAVAILABLE, [
                    '%operation%' => $continuation['operation'],
                ], [
                    'operation_id' => $operationId,
                    'operation' => $continuation['operation'],
                ]),
                Response::HTTP_BAD_REQUEST,
                $request,
            );
        }

        $resource = $this->operationResources->started($result->value());

        return $this->responder->data($resource, Response::HTTP_ACCEPTED, links: $resource['links'] ?? []);
    }

    private function maintenanceActionFromPath(string $path): ?string
    {
        if (1 !== preg_match('#^/api/v1/admin/operations/(cleanup|clear-stale-lock|kill-stale-runner)$#', $path, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function operationIdFromPath(string $path): ?string
    {
        if (1 !== preg_match('#^/api/v1/admin/operations/([a-f0-9]{32})(?:/continue)?$#', $path, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function maintenanceImpact(string $action): array
    {
        return match ($action) {
            'cleanup' => [
                'description' => 'Remove terminal or stale operation run files older than the configured TTL.',
                'ttl_seconds' => 3600,
            ],
            'clear-stale-lock' => [
                'description' => 'Clear the live-operation runner lock only when it is stale.',
                'ttl_seconds' => 3600,
                'runner_lock' => $this->operations->runnerLockStatus(3600),
            ],
            'kill-stale-runner' => [
                'description' => 'Terminate a stale live-operation runner process when possible and clear its stale lock.',
                'ttl_seconds' => 3600,
                'runner_lock' => $this->operations->runnerLockStatus(3600),
            ],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function maintenanceLinks(string $action): array
    {
        return [
            'self' => '/api/v1/admin/operations/'.$action,
            'operations' => '/api/v1/admin/operations',
            'confirm' => '/api/v1/admin/operations/'.$action.'?confirm=true',
        ];
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

    private function notFound(Request $request, string $operationId): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_ENDPOINT_NOT_FOUND, ApiMessageKey::API_ENDPOINT_NOT_FOUND, context: [
                'path' => $request->getPathInfo(),
                'operation_id' => $operationId,
            ]),
            Response::HTTP_NOT_FOUND,
            $request,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function operationUnavailable(Request $request, string $operation, array $context): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_OPERATION_UNAVAILABLE, ApiMessageKey::API_OPERATION_UNAVAILABLE, [
                '%operation%' => $operation,
            ], $context),
            Response::HTTP_CONFLICT,
            $request,
        );
    }
}
