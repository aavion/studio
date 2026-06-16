<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Access\AccessActor;
use App\Core\Diagnostics\SystemInfoProvider;
use App\Core\Geo\GeoIpResolverInterface;
use App\Core\Geo\MaxMindGeoIpConfig;
use App\Core\Log\DatabaseLogBrowser;
use App\Core\Operation\Live\LiveOperationRunStore;
use App\Core\Statistics\AccessStatisticsSnapshotProvider;
use App\Entity\UserAccount;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final readonly class AdminViewContextProvider
{
    public function __construct(
        private LiveOperationRunStore $liveOperationRunStore,
        private DatabaseLogBrowser $logBrowser,
        private AccessStatisticsSnapshotProvider $accessStatisticsSnapshotProvider,
        private SystemInfoProvider $systemInfoProvider,
        private MaxMindGeoIpConfig $maxMindGeoIpConfig,
        private GeoIpResolverInterface $geoIpResolver,
        private Security $security,
        private BackendActions $backendActions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function variables(Request $request, ?BackendViewDefinition $view): array
    {
        if (null === $view || BackendArea::Admin !== $view->area()) {
            return [];
        }

        return match ($view->uid()) {
            'backend-admin-operations' => $this->operationVariables(),
            'backend-admin-logs' => [
                'log_view' => $this->logBrowser->browse($request->query->all()),
            ],
            'backend-admin-statistics' => [
                'access_statistics' => $this->accessStatisticsSnapshotProvider->snapshot($request->query->get('statistics_window')),
                'access_statistics_windows' => $this->accessStatisticsSnapshotProvider->windows(),
            ],
            'backend-admin-settings-system-info' => [
                'system_info' => $this->systemInfoProvider->report($request->server->all()),
            ],
            'backend-admin-settings-statistics' => [
                'geoip_settings' => [
                    'has_license_key' => $this->maxMindGeoIpConfig->hasLicenseKey(),
                    'can_update' => [] !== $this->backendActions->definitions([BackendActions::GEOIP_DATABASE_UPDATE], $this->actor()),
                    'status' => $this->geoIpResolver->status()->toSafeArray(),
                ],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function operationVariables(): array
    {
        return [
            'operation_runs' => $this->liveOperationRunStore->summaries(),
            'operation_lock' => $this->liveOperationRunStore->runnerLockStatus(3600),
        ];
    }

    private function actor(): AccessActor
    {
        $user = $this->security->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }
}
