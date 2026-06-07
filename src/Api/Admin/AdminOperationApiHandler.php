<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Core\Operation\Live\LiveOperationRunStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AdminOperationApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private LiveOperationRunStore $operations,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
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
}
