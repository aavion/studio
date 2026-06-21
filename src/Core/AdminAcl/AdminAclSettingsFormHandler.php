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
        $previousOverrides = $this->store->overrides();
        $overrides = [];

        foreach ($previousOverrides as $feature => $override) {
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

        return new FormSubmissionResult([
            'acl' => $overrides,
            '_audit' => [
                'changed_features' => $this->changedFeatures($previousOverrides, $overrides, $currentIdentifiers),
            ],
        ], []);
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

    /**
     * @param array<string, array<string, mixed>> $previous
     * @param array<string, array<string, mixed>> $next
     * @param array<string, true>                $currentIdentifiers
     *
     * @return list<array{feature: string, previous_state: string|null, next_state: string|null, previous_groups: array<string, string>, next_groups: array<string, string>}>
     */
    private function changedFeatures(array $previous, array $next, array $currentIdentifiers): array
    {
        $features = array_values(array_unique([...array_keys($previous), ...array_keys($next)]));
        sort($features);
        $changed = [];

        foreach ($features as $feature) {
            if (!is_string($feature) || !isset($currentIdentifiers[$feature])) {
                continue;
            }

            $previousRow = $previous[$feature] ?? [];
            $nextRow = $next[$feature] ?? [];
            $previousGroups = $this->auditGroups($previousRow['groups'] ?? []);
            $nextGroups = $this->auditGroups($nextRow['groups'] ?? []);
            $previousState = is_string($previousRow['state'] ?? null) ? $previousRow['state'] : null;
            $nextState = is_string($nextRow['state'] ?? null) ? $nextRow['state'] : null;

            if ($previousState === $nextState && $previousGroups === $nextGroups) {
                continue;
            }

            $changed[] = [
                'feature' => $feature,
                'previous_state' => $previousState,
                'next_state' => $nextState,
                'previous_groups' => $previousGroups,
                'next_groups' => $nextGroups,
            ];
        }

        return $changed;
    }

    /**
     * @param mixed $groups
     *
     * @return array<string, string>
     */
    private function auditGroups(mixed $groups): array
    {
        return is_array($groups) ? $this->normalizeGroups($groups) : [];
    }

    /**
     * @param array<mixed> $groups
     *
     * @return array<string, string>
     */
    private function normalizeGroups(array $groups): array
    {
        $normalized = [];

        foreach ($groups as $identifier => $state) {
            if (is_string($identifier) && is_string($state)) {
                $normalized[$identifier] = $state;
            }
        }

        ksort($normalized);

        return $normalized;
    }
}
