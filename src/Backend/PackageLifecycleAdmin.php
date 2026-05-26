<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageActivator;
use App\Core\Package\PackageFaultResetter;
use App\Core\Package\PackageRemover;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use App\View\SystemPackageMetadataProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class PackageLifecycleAdmin
{
    public const ACTION_ACTIVATE = 'activate';
    public const ACTION_DEACTIVATE = 'deactivate';
    public const ACTION_RESET_FAULT = 'reset-fault';
    public const ACTION_PURGE = 'purge';
    public const ACTION_DELETE = 'delete';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SystemPackageMetadataProvider $systemPackageMetadata,
        private PackageActivator $activator,
        private PackageFaultResetter $faultResetter,
        private PackageRemover $remover,
        private KernelInterface $kernel,
    ) {
    }

    public function package(string $packageName): ?array
    {
        if ('system' === $packageName) {
            return $this->systemPackage();
        }

        $package = $this->entityManager->getRepository(ExtensionPackage::class)->findOneBy([
            'packageName' => $packageName,
        ]);

        return $package instanceof ExtensionPackage ? $this->extensionPackage($package) : null;
    }

    public function review(string $packageName, string $action): array
    {
        $package = $this->package($packageName);
        $plan = null;

        if (null !== $package && !$package['immutable']) {
            $plan = match ($action) {
                self::ACTION_ACTIVATE => $this->activator->planActivation($packageName)->toArray(),
                self::ACTION_DEACTIVATE => $this->deactivationPlan($package)->toArray(),
                self::ACTION_RESET_FAULT => $this->faultResetPlan($package)->toArray(),
                self::ACTION_PURGE => $this->purgePlan($package)->toArray(),
                self::ACTION_DELETE => $this->remover->planRemoval($packageName)->toArray(),
                default => null,
            };
        }

        return [
            'package' => $package,
            'action' => $action,
            'action_key' => str_replace('-', '_', $action),
            'plan' => $plan,
        ];
    }

    /**
     * @return WorkflowResult<array<string, mixed>|null>
     */
    public function apply(string $packageName, string $action): WorkflowResult
    {
        return match ($action) {
            self::ACTION_ACTIVATE => $this->activator->activate($packageName, $this->kernel->getEnvironment()),
            self::ACTION_DEACTIVATE => $this->activator->deactivate($packageName, $this->kernel->getEnvironment()),
            self::ACTION_RESET_FAULT => $this->faultResetter->resetFault($packageName),
            self::ACTION_PURGE => $this->remover->purge($packageName),
            self::ACTION_DELETE => $this->remover->remove($packageName, $this->kernel->getEnvironment()),
            default => WorkflowResult::invalid([
                Message::warning(
                    MessageCode::BACKEND_ACTION_UNKNOWN,
                    MessageKey::BACKEND_ACTION_UNKNOWN,
                    ['%action%' => $action],
                    ['action' => $action, 'package' => $packageName],
                ),
            ]),
        };
    }

    private function systemPackage(): array
    {
        $metadata = $this->systemPackageMetadata->metadata();

        return [
            'package_name' => 'system',
            'label' => $metadata['name'],
            'description' => $metadata['description'],
            'author' => $metadata['author'],
            'path' => '.',
            'status' => ExtensionPackageStatus::Active->value,
            'status_label_key' => 'admin.packages.status.active',
            'status_tone' => 'success',
            'immutable' => true,
            'scopes' => $this->scopeRows($metadata['scopes']),
            'manifest_version' => $metadata['version'],
            'installed_version' => null,
            'manifest' => $metadata['manifest'],
            'actions' => [],
        ];
    }

    private function extensionPackage(ExtensionPackage $package): array
    {
        $metadata = $package->metadata();
        $label = $this->metadataString($metadata, 'display_name') ?? $package->packageName();

        return [
            'package_name' => $package->packageName(),
            'label' => $label,
            'description' => $this->metadataString($metadata, 'description'),
            'author' => $this->metadataString($metadata, 'author'),
            'path' => $package->path(),
            'status' => $package->status()->value,
            'status_label_key' => 'admin.packages.status.'.$package->status()->value,
            'status_tone' => $this->statusTone($package->status()),
            'immutable' => false,
            'scopes' => $this->scopeRows($package->scopeValues()),
            'manifest_version' => $package->manifestVersion(),
            'installed_version' => $package->installedVersion(),
            'manifest' => $this->manifest($metadata),
            'actions' => $this->actions($package),
        ];
    }

    /**
     * @param list<string> $scopes
     *
     * @return list<array{value: string, label_key: string}>
     */
    private function scopeRows(array $scopes): array
    {
        return array_map(static fn (string $scope): array => [
            'value' => $scope,
            'label_key' => 'admin.packages.scope.'.str_replace('-', '_', $scope),
        ], $scopes);
    }

    /**
     * @return list<array{id: string, label_key: string, path: string, variant: string}>
     */
    private function actions(ExtensionPackage $package): array
    {
        $stateActions = match ($package->status()) {
            ExtensionPackageStatus::Inactive => [$this->action($package, self::ACTION_ACTIVATE, 'primary')],
            ExtensionPackageStatus::Active => [$this->action($package, self::ACTION_DEACTIVATE, 'secondary')],
            ExtensionPackageStatus::Faulty => [$this->action($package, self::ACTION_RESET_FAULT, 'secondary')],
            ExtensionPackageStatus::Removed => [],
        };

        $cleanupActions = [
            $this->action($package, self::ACTION_PURGE, 'danger'),
        ];

        if (ExtensionPackageStatus::Removed !== $package->status()) {
            $cleanupActions[] = $this->action($package, self::ACTION_DELETE, 'danger');
        }

        return [...$stateActions, ...$cleanupActions];
    }

    private function action(ExtensionPackage $package, string $action, string $variant): array
    {
        return [
            'id' => $action,
            'label_key' => 'admin.packages.lifecycle.'.$this->actionKey($action).'.label',
            'path' => $this->actionPath($package->packageName(), $action),
            'variant' => $variant,
        ];
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    private function deactivationPlan(array $package): WorkflowResult
    {
        return WorkflowResult::success([
            'package' => $package['package_name'],
            'changes' => [[
                'package' => $package['package_name'],
                'action' => 'deactivated',
                'status' => ExtensionPackageStatus::Inactive->value,
            ]],
            'asset_rebuild' => ExtensionPackageStatus::Active->value === $package['status'],
        ]);
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    private function faultResetPlan(array $package): WorkflowResult
    {
        return WorkflowResult::success([
            'package' => $package['package_name'],
            'changes' => [[
                'package' => $package['package_name'],
                'action' => 'fault_reset',
                'status' => ExtensionPackageStatus::Inactive->value,
            ]],
            'asset_rebuild' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    private function purgePlan(array $package): WorkflowResult
    {
        return WorkflowResult::success([
            'package' => $package['package_name'],
            'changes' => [[
                'package' => $package['package_name'],
                'action' => 'purged',
                'status' => 'deleted',
            ]],
            'asset_rebuild' => false,
        ]);
    }

    private function actionPath(string $packageName, string $action): string
    {
        return '/admin/packages/'.rawurlencode($packageName).'/'.$action;
    }

    private function actionKey(string $action): string
    {
        return str_replace('-', '_', $action);
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, string>
     */
    private function manifest(array $metadata): array
    {
        $manifest = $metadata['manifest'] ?? [];

        if (!is_array($manifest)) {
            return [];
        }

        $rows = [];

        foreach ($manifest as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $rows[$key] = (string) $value;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function metadataString(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    private function statusTone(ExtensionPackageStatus $status): string
    {
        return match ($status) {
            ExtensionPackageStatus::Active => 'success',
            ExtensionPackageStatus::Inactive => 'neutral',
            ExtensionPackageStatus::Removed => 'warning',
            ExtensionPackageStatus::Faulty => 'error',
        };
    }
}
