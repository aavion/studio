<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PackageActivator
{
    private PackageLifecycleStore $store;
    private PackageActivationPlanner $planner;
    private PackageLifecycleFinalizer $finalizer;

    public function __construct(
        EntityManagerInterface $entityManager,
        PackageLifecycleAssetRebuilderInterface $assetRebuilder,
        private WorkflowResultMessageReporterInterface $messageReporter,
        ?PackageDependencyResolver $dependencyResolver = null,
        ?PackageLifecycleStore $store = null,
        ?PackageActivationPlanner $planner = null,
        ?PackageLifecycleFinalizer $finalizer = null,
    ) {
        $this->store = $store ?? new PackageLifecycleStore($entityManager);
        $dependencyResolver ??= new PackageDependencyResolver($entityManager);
        $this->planner = $planner ?? new PackageActivationPlanner($this->store, $dependencyResolver);
        $this->finalizer = $finalizer ?? new PackageLifecycleFinalizer($entityManager, $this->store, $assetRebuilder);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function planActivation(string $packageName): WorkflowResult
    {
        return $this->report($this->planner->planActivation($packageName), 'package.activate.plan', ['package' => $packageName]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function planDeactivation(string $packageName): WorkflowResult
    {
        return $this->report($this->planner->planDeactivation($packageName), 'package.deactivate.plan', ['package' => $packageName]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function activate(string $packageName, string $environment, bool $rebuildAssets = true): WorkflowResult
    {
        $plan = $this->planner->planActivation($packageName);

        if (!$plan->isSuccess()) {
            return $this->report($plan, 'package.activate', ['package' => $packageName, 'environment' => $environment]);
        }

        $packages = $this->store->packagesByName($plan->value()['activate']);
        $conflicts = $this->store->packagesByName($plan->value()['deactivate']);
        $snapshots = $this->store->statusSnapshots([...$packages, ...$conflicts]);
        $changes = [];
        $messages = $plan->messages();

        foreach ($conflicts as $conflict) {
            if ($conflict->deactivate()) {
                $changes[] = $this->change($conflict, 'deactivated');
                $messages[] = $this->deactivatedMessage($conflict);
            }
        }

        foreach ($packages as $package) {
            if ($package->activate()) {
                $changes[] = $this->change($package, 'activated');
                $messages[] = Message::create(
                    PackageMessageCode::PACKAGE_LIFECYCLE_ACTIVATED,
                    PackageMessageKey::PACKAGE_LIFECYCLE_ACTIVATED,
                    ['%package%' => $package->packageName()],
                    ['package' => $package->packageName()],
                    MessageLevel::Success,
                );
            }
        }

        return $this->report(
            $this->finalizer->finalize($snapshots, $changes, $messages, $environment, $rebuildAssets),
            'package.activate',
            ['package' => $packageName, 'environment' => $environment],
        );
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function deactivate(string $packageName, string $environment, bool $rebuildAssets = true): WorkflowResult
    {
        $plan = $this->planner->planDeactivation($packageName);

        if (!$plan->isSuccess()) {
            return $this->report($plan, 'package.deactivate', ['package' => $packageName, 'environment' => $environment]);
        }

        $packages = $this->store->packagesByName($plan->value()['deactivate']);
        $snapshots = $this->store->statusSnapshots($packages);
        $changes = [];
        $messages = [];

        foreach ($packages as $package) {
            if (ExtensionPackageStatus::Active === $package->status() && $package->deactivate()) {
                $changes[] = $this->change($package, 'deactivated');
                $messages[] = $this->deactivatedMessage($package);
            }
        }

        return $this->report(
            $this->finalizer->finalize($snapshots, $changes, $messages, $environment, $rebuildAssets),
            'package.deactivate',
            ['package' => $packageName, 'environment' => $environment],
        );
    }

    /**
     * @return array{package: string, action: string, status: string}
     */
    private function change(ExtensionPackage $package, string $action): array
    {
        return [
            'package' => $package->packageName(),
            'action' => $action,
            'status' => $package->status()->value,
        ];
    }

    private function deactivatedMessage(ExtensionPackage $package): Message
    {
        return Message::create(
            PackageMessageCode::PACKAGE_LIFECYCLE_DEACTIVATED,
            PackageMessageKey::PACKAGE_LIFECYCLE_DEACTIVATED,
            ['%package%' => $package->packageName()],
            ['package' => $package->packageName()],
            MessageLevel::Success,
        );
    }

    private function report(WorkflowResult $result, string $operation, array $context = []): WorkflowResult
    {
        return $this->messageReporter->report($result, [
            ...$context,
            'operation' => $operation,
        ]);
    }
}
