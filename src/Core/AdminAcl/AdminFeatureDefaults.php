<?php

declare(strict_types=1);

namespace App\Core\AdminAcl;

final readonly class AdminFeatureDefaults
{
    /**
     * @return array<string, array{state: string, groups: array<string, string>}>
     */
    public function overrides(): array
    {
        return [
            'admin.settings.logging' => $this->row(AdminPermissionState::Visible),
            'admin.settings.statistics' => $this->row(AdminPermissionState::Mutable),
            'admin.settings.statistics.geoip' => $this->row(AdminPermissionState::Visible),
            'admin.settings.api' => $this->row(AdminPermissionState::Denied),
            'admin.settings.scheduler' => $this->row(AdminPermissionState::Visible),
            'admin.logs' => $this->row(AdminPermissionState::Visible),
            'admin.extensions' => $this->row(AdminPermissionState::Visible),
            'admin.operations' => $this->row(AdminPermissionState::Visible),
            'admin.actions.maintenance' => $this->row(AdminPermissionState::Mutable),
            'admin.scheduler' => $this->row(AdminPermissionState::Visible),
            'admin.settings.extensions' => $this->row(AdminPermissionState::Visible),
            'admin.users' => $this->row(AdminPermissionState::Mutable),
            'admin.users.acl' => $this->row(AdminPermissionState::Mutable),
            'admin.users.review' => $this->row(AdminPermissionState::Mutable),
        ];
    }

    /**
     * @return array{state: string, groups: array<string, string>}
     */
    private function row(AdminPermissionState $state): array
    {
        return [
            'state' => $state->value,
            'groups' => [],
        ];
    }
}
