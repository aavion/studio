<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

use App\Core\Manifest\Manifest;
use App\Core\Message\Message;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageActivator;
use App\Core\Package\PackageDiscoveryRunner;
use App\Core\Package\PackageManifestSpec;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Core\Package\PackageScope;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use Throwable;

final readonly class PackageInstallApplier
{
    public function __construct(
        private PackageDiscoveryRunner $discoveryRunner,
        private PackageActivator $activator,
        private PackageInstallFilesystem $filesystem,
        private PackageInstallPayload $payloadReader,
        private PackageInstallStageReader $stageReader,
        private PackageInstallRegistry $registry,
        private PackageInstallVersionGuard $versionGuard,
        private PackageReplacementPreflight $replacementPreflight,
        private PackageInstallRollbacker $rollbacker,
        private PackageReactivationPlanner $reactivationPlanner,
        private string $environment,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    public function apply(array $payload): WorkflowResult
    {
        $installId = $this->payloadReader->string($payload, 'install_id');
        $slug = $this->payloadReader->string($payload, 'package');

        if (null === $installId || null === $slug || !PackageManifestSpec::isValidSlug($slug)) {
            return $this->payloadReader->invalid('apply', array_keys($payload));
        }

        $root = $this->filesystem->installRoot($this->environment, $installId);
        $stagePath = $root.DIRECTORY_SEPARATOR.'staged';
        $packageRoot = $this->stageReader->packageRoot($stagePath);

        if (null === $packageRoot) {
            return WorkflowResult::invalid([
                Message::error(
                    PackageMessageCode::PACKAGE_INSTALL_ROOT_INVALID,
                    PackageMessageKey::PACKAGE_INSTALL_ROOT_INVALID,
                    context: ['install_id' => $installId, 'package' => $slug],
                ),
            ]);
        }

        $symlink = $this->filesystem->firstSymlinkPath($packageRoot);
        if (null !== $symlink) {
            return WorkflowResult::invalid([
                Message::warning(
                    PackageMessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                    PackageMessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                    context: [
                        'install_id' => $installId,
                        'reason' => 'symlink_entry',
                        'entry' => $this->filesystem->relativePath($symlink),
                    ],
                ),
            ]);
        }

        $messages = [];
        $existing = $this->registry->package($slug);
        $wasActive = $existing instanceof ExtensionPackage && ExtensionPackageStatus::Active === $existing->status();
        $previousStatus = $existing?->status();
        $target = $this->filesystem->packageTarget($slug);
        $prepared = $root.DIRECTORY_SEPARATOR.'prepared'.DIRECTORY_SEPARATOR.$slug;
        $backup = $root.DIRECTORY_SEPARATOR.'backup'.DIRECTORY_SEPARATOR.$slug;
        $deactivationTargets = [];
        $previousStatuses = $previousStatus instanceof ExtensionPackageStatus ? [$slug => $previousStatus] : [];

        $manifest = $this->stageReader->readManifest($packageRoot);
        if (!$manifest->isSuccess()) {
            return $manifest;
        }

        $manifestValue = $manifest->value();
        try {
            $scopes = PackageScope::fromManifestValue((string) $manifestValue->get('PACKAGE_SCOPE', ''));
        } catch (\InvalidArgumentException) {
            return WorkflowResult::invalid([
                Message::error(
                    PackageMessageCode::PACKAGE_SCOPE_INVALID,
                    PackageMessageKey::PACKAGE_SCOPE_INVALID,
                    ['%scope%' => (string) $manifestValue->get('PACKAGE_SCOPE', '')],
                    ['install_id' => $installId, 'package' => $slug],
                ),
            ]);
        }

        $versionGate = $this->versionGuard->ensureAllowed($manifestValue, $existing, $installId, $slug);
        if ($versionGate instanceof WorkflowResult) {
            return $versionGate;
        }

        if ($wasActive) {
            $dependencyPreflight = $this->replacementPreflight->dependencies($manifestValue, $slug, $scopes);

            if (!$dependencyPreflight->isSuccess()) {
                return WorkflowResult::blocked($dependencyPreflight->issues(), [
                    'install_id' => $installId,
                    'package' => $slug,
                    'dependencies' => $dependencyPreflight->context()['dependencies'] ?? [],
                ], $dependencyPreflight->messages());
            }

            $deactivationPlan = $this->activator->planDeactivation($slug);

            if (!$deactivationPlan->isSuccess()) {
                return WorkflowResult::blocked($deactivationPlan->issues(), [
                    'install_id' => $installId,
                    'package' => $slug,
                    'deactivation_context' => $deactivationPlan->context(),
                ], $deactivationPlan->messages());
            }

            $deactivationTargets = $this->replacementPreflight->packageNameList($deactivationPlan->value()['deactivate'] ?? []);
            $previousStatuses = $this->registry->statusSnapshots($deactivationTargets);
            $deactivation = $this->activator->deactivate($slug, $this->environment, rebuildAssets: false);
            $messages = [...$messages, ...$deactivation->messages()];

            if (!$deactivation->isSuccess()) {
                return WorkflowResult::failed($deactivation->issues(), [
                    'install_id' => $installId,
                    'package' => $slug,
                    'deactivation_context' => $deactivation->context(),
                ], $messages);
            }
        }

        try {
            $this->filesystem->prepareReplacement($packageRoot, $prepared);
            $this->filesystem->swapPreparedPackage($prepared, $target, $backup);
        } catch (Throwable $error) {
            $rollbackMessages = $this->registry->restoreStatuses($previousStatuses);

            return WorkflowResult::failed([
                Message::exception(
                    OperationMessageCode::OPERATION_EXCEPTION,
                    OperationMessageKey::OPERATION_EXCEPTION,
                    context: [
                        'install_id' => $installId,
                        'package' => $slug,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                ),
            ], [
                'install_id' => $installId,
                'package' => $slug,
                'replacement_stage' => 'filesystem_swap',
                'rolled_back' => [] === $rollbackMessages,
            ], [...$messages, ...$rollbackMessages]);
        }

        $discovery = ($this->discoveryRunner)('package_install');
        $messages = [...$messages, ...$discovery->messages()];

        if (!$discovery->isSuccess()) {
            $rollbackMessages = $this->rollbacker->previousPackage($slug, $target, $backup, $previousStatuses);

            return WorkflowResult::failed($discovery->issues(), [
                'install_id' => $installId,
                'package' => $slug,
                'discovery_context' => $discovery->context(),
                'rolled_back' => [] === $rollbackMessages,
            ], [...$messages, ...$rollbackMessages]);
        }

        $installed = $this->registry->package($slug);
        if ($installed instanceof ExtensionPackage && ExtensionPackageStatus::Active === $installed->status()) {
            $installed->restoreStatus(ExtensionPackageStatus::Inactive);
            $this->registry->flush();
        }

        $installed = $this->registry->package($slug);
        if (!$this->isInstalledInactivePackage($installed, $manifestValue)) {
            $rollbackMessages = $this->rollbacker->previousPackage($slug, $target, $backup, $previousStatuses);
            $status = $installed?->status()->value;

            return WorkflowResult::failed([
                Message::error(
                    PackageMessageCode::PACKAGE_REGISTRY_PACKAGE_FAULTY,
                    PackageMessageKey::PACKAGE_REGISTRY_PACKAGE_FAULTY,
                    ['%package%' => $slug],
                    [
                        'install_id' => $installId,
                        'package' => $slug,
                        'status' => $status,
                        'expected_status' => ExtensionPackageStatus::Inactive->value,
                    ],
                ),
            ], [
                'install_id' => $installId,
                'package' => $slug,
                'status' => $status,
                'rolled_back' => [] === $rollbackMessages,
            ], [...$messages, ...$rollbackMessages]);
        }

        if ($wasActive) {
            $reactivationTargets = $this->reactivationPlanner->order($slug, $deactivationTargets, $previousStatuses);
            $activation = $this->activator->activate($slug, $this->environment, rebuildAssets: [] === $reactivationTargets);
            $messages = [...$messages, ...$activation->messages()];

            if (!$activation->isSuccess()) {
                $rollbackMessages = $this->rollbacker->previousPackage($slug, $target, $backup, $previousStatuses);

                return WorkflowResult::failed($activation->issues(), [
                    'install_id' => $installId,
                    'package' => $slug,
                    'activation_context' => $activation->context(),
                    'rolled_back' => [] === $rollbackMessages,
                ], [...$messages, ...$rollbackMessages]);
            }

            $lastReactivationIndex = count($reactivationTargets) - 1;
            foreach ($reactivationTargets as $index => $packageName) {
                $reactivation = $this->activator->activate($packageName, $this->environment, rebuildAssets: $index === $lastReactivationIndex);
                $messages = [...$messages, ...$reactivation->messages()];

                if (!$reactivation->isSuccess()) {
                    $rollbackMessages = $this->rollbacker->previousPackage($slug, $target, $backup, $previousStatuses);

                    return WorkflowResult::failed($reactivation->issues(), [
                        'install_id' => $installId,
                        'package' => $slug,
                        'reactivation_package' => $packageName,
                        'reactivation_context' => $reactivation->context(),
                        'rolled_back' => [] === $rollbackMessages,
                    ], [...$messages, ...$rollbackMessages]);
                }
            }
        }

        $this->filesystem->removePath($root);

        return WorkflowResult::success([
            'package' => $slug,
            'was_active' => $wasActive,
        ], [
            'package' => $slug,
            'was_active' => $wasActive,
        ], [
            ...$messages,
            Message::success(
                PackageMessageKey::PACKAGE_INSTALL_COMPLETED,
                ['%package%' => $slug],
                ['package' => $slug, 'was_active' => $wasActive],
            ),
        ]);
    }

    private function isInstalledInactivePackage(?ExtensionPackage $package, Manifest $manifest): bool
    {
        if (!$package instanceof ExtensionPackage || ExtensionPackageStatus::Inactive !== $package->status()) {
            return false;
        }

        $expectedVersion = trim((string) $manifest->get('PACKAGE_VERSION', ''));

        return '' === $expectedVersion || $package->manifestVersion() === $expectedVersion;
    }
}
