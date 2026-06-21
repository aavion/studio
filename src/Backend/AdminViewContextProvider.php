<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Access\AccessActor;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\AdminAcl\AdminFeatureDefinition;
use App\Core\AdminAcl\AdminFeatureOverrideStore;
use App\Core\AdminAcl\AdminFeatureRegistry;
use App\Core\AdminAcl\AdminPermissionState;
use App\Core\AdminAcl\AdminPermissionSurface;
use App\Core\Diagnostics\SystemInfoProvider;
use App\Core\Geo\GeoIpResolverInterface;
use App\Core\Geo\MaxMindGeoIpConfig;
use App\Core\Log\AdminLogBrowser;
use App\Core\Operation\Live\LiveOperationRunStore;
use App\Core\Statistics\AccessStatisticsSnapshotProvider;
use App\Entity\UserAccount;
use App\Security\AutoBan\AutoBanPolicy;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final readonly class AdminViewContextProvider
{
    public function __construct(
        private LiveOperationRunStore $liveOperationRunStore,
        private AdminLogBrowser $logBrowser,
        private AccessStatisticsSnapshotProvider $accessStatisticsSnapshotProvider,
        private SystemInfoProvider $systemInfoProvider,
        private MaxMindGeoIpConfig $maxMindGeoIpConfig,
        private GeoIpResolverInterface $geoIpResolver,
        private Security $security,
        private BackendActions $backendActions,
        private AdminFeatureRegistry $adminFeatureRegistry,
        private AdminFeatureAccessPolicy $adminFeatureAccessPolicy,
        private AdminFeatureOverrideStore $adminFeatureOverrideStore,
        private AutoBanPolicy $autoBanPolicy,
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
            'backend-admin-logs' => $this->logVariables($request),
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
            'backend-admin-settings-security' => [
                'auto_ban_enabled' => $this->autoBanPolicy->enabled(),
            ],
            'backend-admin-settings-acl' => [
                'acl_matrix' => $this->aclMatrix(),
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
            'operations_mutable' => $this->adminFeatureAccessPolicy->isMutable('admin.operations', $this->actor()),
            'operation_runs' => $this->liveOperationRunStore->summaries(),
            'operation_lock' => $this->liveOperationRunStore->runnerLockStatus(3600),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function logVariables(Request $request): array
    {
        $actor = $this->actor();
        $mutable = $this->adminFeatureAccessPolicy->isMutable('admin.logs', $actor);
        $query = $request->query->all();

        if (!$mutable && $this->isSensitiveLogSource($query['source'] ?? null)) {
            $query['source'] = 'message';
        }

        $view = $this->logBrowser->browse($query);

        if (!$mutable) {
            $view['sources'] = array_values(array_filter(
                $view['sources'] ?? [],
                fn (array $source): bool => !$this->isSensitiveLogSource($source['key'] ?? null),
            ));
        }

        return ['log_view' => $view];
    }

    private function isSensitiveLogSource(mixed $source): bool
    {
        return is_string($source) && in_array($source, ['audit', 'security_signal'], true);
    }

    private function actor(): AccessActor
    {
        $user = $this->security->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }

    /**
     * @return array<string, mixed>
     */
    private function aclMatrix(): array
    {
        $overrides = $this->adminFeatureOverrideStore->overrides();
        $defaults = $this->adminFeatureOverrideStore->defaultOverrides();
        $surfaces = [];

        foreach (AdminPermissionSurface::cases() as $surface) {
            $groups = $this->adminFeatureAccessPolicy->availableGroups($surface);
            $surfaces[] = [
                'key' => $surface->value,
                'label_key' => $surface->labelKey(),
                'groups' => $groups,
                'rows' => array_map(
                    fn (AdminFeatureDefinition $definition): array => $this->aclMatrixRow($definition, $overrides[$definition->identifier()] ?? [], $defaults[$definition->identifier()] ?? [], $groups),
                    $this->adminFeatureRegistry->definitions($surface),
                ),
            ];
        }

        return [
            'states' => array_map(
                static fn (AdminPermissionState $state): array => [
                    'value' => $state->value,
                    'label_key' => 'admin.acl.states.'.$state->value,
                ],
                AdminPermissionState::cases(),
            ),
            'group_states' => [
                [
                    'value' => '',
                    'label_key' => 'admin.acl.states.inherit',
                ],
                ...array_map(
                    static fn (AdminPermissionState $state): array => [
                        'value' => $state->value,
                        'label_key' => 'admin.acl.states.'.$state->value,
                    ],
                    AdminPermissionState::cases(),
                ),
            ],
            'surfaces' => $surfaces,
        ];
    }

    /**
     * @param array<string, mixed> $override
     * @param list<array{identifier: string, name: string, min_role: int}> $groups
     *
     * @return array<string, mixed>
     */
    private function aclMatrixRow(AdminFeatureDefinition $definition, array $override, array $defaultOverride, array $groups): array
    {
        $groupOverrides = is_array($override['groups'] ?? null) ? $override['groups'] : [];

        return [
            'identifier' => $definition->identifier(),
            'label_key' => $definition->labelKey(),
            'description_key' => $definition->descriptionKey(),
            'category_key' => $definition->categoryKey(),
            'configurable' => $definition->configurable(),
            'default_state' => AdminPermissionState::fromMixed($defaultOverride['state'] ?? null, $definition->defaultState())->value,
            'state' => AdminPermissionState::fromMixed($override['state'] ?? null, $definition->defaultState())->value,
            'groups' => array_map(
                static fn (array $group): array => [
                    ...$group,
                    'state' => is_string($groupOverrides[$group['identifier']] ?? null) ? (string) $groupOverrides[$group['identifier']] : '',
                ],
                $groups,
            ),
        ];
    }
}
