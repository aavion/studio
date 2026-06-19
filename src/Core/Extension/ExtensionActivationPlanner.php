<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;

final readonly class ExtensionActivationPlanner
{
    public function __construct(
        private ExtensionLifecycleStore $store,
        private ExtensionDependencyResolver $dependencyResolver,
    ) {
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function planActivation(string $extensionName): WorkflowResult
    {
        $extension = $this->store->extension($extensionName);

        if (null === $extension) {
            return $this->extensionNotFound($extensionName);
        }

        if ($this->isActivationBlocked($extension)) {
            return $this->statusBlocked($extension);
        }

        $dependencies = $this->dependencyResolver->resolve($extension);

        if (!$dependencies->isSuccess()) {
            return WorkflowResult::blocked($dependencies->issues(), [
                'extension' => $extensionName,
                'dependencies' => $dependencies->context()['dependencies'] ?? [],
            ]);
        }

        $extensions = $dependencies->value()['extensions'];
        $conflicts = $this->singleActiveConflictsFor($extensions);
        $deactivations = $this->deactivationCascadeFor($conflicts, array_map(
            static fn (Extension $candidate): string => $candidate->extensionName(),
            $extensions,
        ));
        $changes = [];

        foreach ($deactivations as $deactivation) {
            $changes[] = $this->change($deactivation, 'deactivated', ExtensionStatus::Inactive);
        }

        foreach ($extensions as $candidate) {
            if (ExtensionStatus::Active !== $candidate->status()) {
                $changes[] = $this->change($candidate, 'activated', ExtensionStatus::Active);
            }
        }

        return WorkflowResult::success([
            'extension' => $extensionName,
            'dependencies' => $dependencies->value()['dependencies'],
            'activate' => array_map(static fn (Extension $candidate): string => $candidate->extensionName(), $extensions),
            'deactivate' => array_map(static fn (Extension $candidate): string => $candidate->extensionName(), $deactivations),
            'changes' => $changes,
            'asset_rebuild' => [] !== $changes,
        ], [
            'extension' => $extensionName,
            'dependencies' => $dependencies->value()['dependencies'],
            'changes' => $changes,
        ], $dependencies->messages());
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function planDeactivation(string $extensionName): WorkflowResult
    {
        $extension = $this->store->extension($extensionName);

        if (null === $extension) {
            return $this->extensionNotFound($extensionName);
        }

        $extensions = $this->deactivationCascadeFor([$extension]);
        $changes = [];

        foreach ($extensions as $candidate) {
            if (ExtensionStatus::Active === $candidate->status()) {
                $changes[] = $this->change($candidate, 'deactivated', ExtensionStatus::Inactive);
            }
        }

        return WorkflowResult::success([
            'extension' => $extensionName,
            'deactivate' => array_map(static fn (Extension $candidate): string => $candidate->extensionName(), $extensions),
            'changes' => $changes,
            'asset_rebuild' => [] !== $changes,
        ], [
            'extension' => $extensionName,
            'changes' => $changes,
        ]);
    }

    /**
     * @return list<Extension>
     */
    private function singleActiveConflicts(Extension $extension): array
    {
        $singleActiveScopes = array_map(
            static fn (ExtensionScope $scope): string => $scope->value,
            array_filter($extension->scopes(), static fn (ExtensionScope $scope): bool => $scope->isSingleActive()),
        );

        if ([] === $singleActiveScopes) {
            return [];
        }

        $conflicts = [];

        foreach ($this->store->activeExtensions() as $candidate) {
            if (
                $candidate->extensionName() !== $extension->extensionName()
                && $this->store->isManagedFilesystemExtension($candidate)
                && [] !== array_intersect($singleActiveScopes, $candidate->scopeValues())
            ) {
                $conflicts[] = $candidate;
            }
        }

        return $conflicts;
    }

    /**
     * @param list<Extension> $extensions
     *
     * @return list<Extension>
     */
    private function singleActiveConflictsFor(array $extensions): array
    {
        $activatingNames = array_fill_keys(array_map(static fn (Extension $extension): string => $extension->extensionName(), $extensions), true);
        $conflicts = [];

        foreach ($extensions as $extension) {
            foreach ($this->singleActiveConflicts($extension) as $conflict) {
                if (!isset($activatingNames[$conflict->extensionName()])) {
                    $conflicts[$conflict->extensionName()] = $conflict;
                }
            }
        }

        return array_values($conflicts);
    }

    /**
     * @param list<Extension> $extensions
     * @param list<string> $excludedExtensionNames
     *
     * @return list<Extension>
     */
    private function deactivationCascadeFor(array $extensions, array $excludedExtensionNames = []): array
    {
        $excluded = array_fill_keys($excludedExtensionNames, true);
        $deactivations = [];

        foreach ($extensions as $extension) {
            foreach ($this->dependencyResolver->activeDependentsOf($extension, [...array_keys($excluded), ...array_keys($deactivations)]) as $dependent) {
                if (isset($excluded[$dependent->extensionName()]) || isset($deactivations[$dependent->extensionName()])) {
                    continue;
                }

                $deactivations[$dependent->extensionName()] = $dependent;
            }

            if (isset($excluded[$extension->extensionName()]) || isset($deactivations[$extension->extensionName()])) {
                continue;
            }

            $deactivations[$extension->extensionName()] = $extension;
        }

        return array_values($deactivations);
    }

    private function isActivationBlocked(Extension $extension): bool
    {
        return in_array($extension->status(), [
            ExtensionStatus::Removed,
            ExtensionStatus::Faulty,
        ], true);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function extensionNotFound(string $extensionName): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::create(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_EXTENSION_NOT_FOUND,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_EXTENSION_NOT_FOUND,
                ['%extension%' => $extensionName],
                ['extension' => $extensionName],
                MessageLevel::Error,
            ),
        ]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function statusBlocked(Extension $extension): WorkflowResult
    {
        return WorkflowResult::blocked([
            Message::create(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_STATUS_BLOCKED,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_STATUS_BLOCKED,
                ['%extension%' => $extension->extensionName(), '%status%' => $extension->status()->value],
                ['extension' => $extension->extensionName(), 'status' => $extension->status()->value],
                MessageLevel::Warning,
            ),
        ]);
    }

    /**
     * @return array{extension: string, action: string, status: string}
     */
    private function change(Extension $extension, string $action, ExtensionStatus $status): array
    {
        return [
            'extension' => $extension->extensionName(),
            'action' => $action,
            'status' => $status->value,
        ];
    }
}
