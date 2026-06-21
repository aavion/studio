<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Core\Statistics\AccessStatisticsSnapshotProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AdminStatisticsApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private AccessStatisticsSnapshotProvider $statistics,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
        private AdminFeatureApiGuard $featureGuard,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return AdminOperationalApiEndpointProvider::HANDLER_STATISTICS;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        if ($denied = $this->featureGuard->denyUnlessVisible($request, 'admin.settings.statistics', 'getAdminStatistics')) {
            return $denied;
        }

        return $this->responder->data([
            'type' => 'access_statistics',
            'id' => (string) $request->query->get('statistics_window', '24h'),
            'attributes' => $this->statistics->snapshot($request->query->get('statistics_window')),
        ], meta: [
            'windows' => $this->statistics->windows(),
        ]);
    }
}
