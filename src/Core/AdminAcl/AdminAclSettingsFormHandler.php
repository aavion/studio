<?php

declare(strict_types=1);

namespace App\Core\AdminAcl;

use App\Form\FormErrorKey;
use App\Form\FormSubmissionResult;

final readonly class AdminAclSettingsFormHandler
{
    public function __construct(
        private AdminFeatureRegistry $registry,
        private AdminFeatureAccessPolicy $policy,
        private AdminFeatureOverrideStore $store,
    ) {
    }

    /**
     * @param array<string, mixed> $submitted
     */
    public function submit(array $submitted, ?string $modifiedBy = null): FormSubmissionResult
    {
        $values = $submitted['acl'] ?? [];

        if (!is_array($values)) {
            return new FormSubmissionResult($submitted, ['__form' => [FormErrorKey::INVALID]]);
        }

        $currentDefinitions = $this->registry->definitions(AdminPermissionSurface::Admin);
        $currentIdentifiers = array_fill_keys(array_map(
            static fn (AdminFeatureDefinition $definition): string => $definition->identifier(),
            $currentDefinitions,
        ), true);
        $overrides = [];

        foreach ($this->store->overrides() as $feature => $override) {
            if (!isset($currentIdentifiers[$feature])) {
                $overrides[$feature] = $override;
            }
        }

        foreach ($currentDefinitions as $definition) {
            if (!$definition->configurable()) {
                continue;
            }

            $row = $values[$definition->identifier()] ?? [];
            if (!is_array($row)) {
                continue;
            }

            $state = AdminPermissionState::fromMixed($row['state'] ?? null, $definition->defaultState());
            $groups = $this->groupOverrides($row, $definition->surface());

            $overrides[$definition->identifier()] = [
                'state' => $state->value,
                'groups' => $groups,
            ];
        }

        if (!$this->store->save($overrides, $modifiedBy)) {
            return new FormSubmissionResult($submitted, ['__form' => [FormErrorKey::SAVE_FAILED]]);
        }

        $this->registry->resetCache();

        return new FormSubmissionResult(['acl' => $overrides], []);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function groupOverrides(array $row, AdminPermissionSurface $surface): array
    {
        $submittedGroups = $row['groups'] ?? [];

        if (!is_array($submittedGroups)) {
            return [];
        }

        $allowed = array_fill_keys(array_map(
            static fn (array $group): string => $group['identifier'],
            $this->policy->availableGroups($surface),
        ), true);
        $groups = [];

        foreach ($submittedGroups as $identifier => $state) {
            if (!is_string($identifier) || !isset($allowed[$identifier])) {
                continue;
            }

            $groupState = is_string($state) ? AdminPermissionState::tryFrom($state) : null;
            if ($groupState instanceof AdminPermissionState) {
                $groups[$identifier] = $groupState->value;
            }
        }

        ksort($groups);

        return $groups;
    }
}
