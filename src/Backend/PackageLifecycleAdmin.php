<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Filesystem\PathGuard;
use App\Core\Manifest\Manifest;
use App\Core\Manifest\ManifestParser;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageActivator;
use App\Core\Package\PackageFaultResetter;
use App\Core\Package\PackageRemover;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use App\View\SystemPackageMetadataProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Throwable;

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
        private ManifestParser $manifestParser = new ManifestParser(),
        private PathGuard $pathGuard = new PathGuard(),
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
                self::ACTION_DEACTIVATE => $this->activator->planDeactivation($packageName)->toArray(),
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
            'license' => $metadata['license'] ?? null,
            'dependencies' => [],
            'homepage' => $metadata['homepage'] ?? null,
            'homepage_url' => $this->safeExternalUrl($metadata['homepage'] ?? null),
            'source' => $metadata['source'] ?? null,
            'source_url' => $this->sourceUrl($metadata['source'] ?? null, $metadata['channel'] ?? null),
            'readme' => $this->readReadme('.'),
            'preview_image' => $this->previewImageDataUri('.', $metadata['image'] ?? null),
            'actions' => [],
        ];
    }

    private function extensionPackage(ExtensionPackage $package): array
    {
        $metadata = $package->metadata();
        $manifest = $this->readManifest($package->path());
        $label = $this->metadataString($metadata, 'display_name') ?? $package->packageName();
        $dependencies = $manifest?->get('PACKAGE_DEPENDENCIES') ?? $this->metadataString($metadata, 'dependencies');
        $source = $this->metadataString($metadata, 'source') ?? $manifest?->get('PACKAGE_SOURCE');
        $channel = $this->metadataString($metadata, 'channel') ?? $manifest?->get('PACKAGE_CHANNEL');

        return [
            'package_name' => $package->packageName(),
            'label' => $label,
            'description' => $this->metadataString($metadata, 'description') ?? $manifest?->get('PACKAGE_DESCRIPTION'),
            'author' => $this->metadataString($metadata, 'author') ?? $manifest?->get('PACKAGE_AUTHOR'),
            'path' => $package->path(),
            'status' => $package->status()->value,
            'status_label_key' => 'admin.packages.status.'.$package->status()->value,
            'status_tone' => $this->statusTone($package->status()),
            'immutable' => false,
            'scopes' => $this->scopeRows($package->scopeValues()),
            'manifest_version' => $package->manifestVersion(),
            'installed_version' => $package->installedVersion(),
            'license' => $this->metadataString($metadata, 'license') ?? $manifest?->get('PACKAGE_LICENSE'),
            'dependencies' => $this->dependencies($dependencies),
            'homepage' => $this->metadataString($metadata, 'homepage') ?? $manifest?->get('PACKAGE_HOMEPAGE'),
            'homepage_url' => $this->safeExternalUrl($this->metadataString($metadata, 'homepage') ?? $manifest?->get('PACKAGE_HOMEPAGE')),
            'source' => $source,
            'source_url' => $this->sourceUrl($source, $channel),
            'readme' => $this->readReadme($package->path()),
            'preview_image' => $this->previewImageDataUri($package->path(), $this->metadataString($metadata, 'image') ?? $manifest?->get('PACKAGE_IMAGE')),
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

        $cleanupActions = ExtensionPackageStatus::Removed === $package->status()
            ? [$this->action($package, self::ACTION_PURGE, 'danger')]
            : [];

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
        if (ExtensionPackageStatus::Removed->value !== ($package['status'] ?? null)) {
            return WorkflowResult::blocked([
                Message::create(
                    MessageCode::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                    MessageKey::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                    ['%package%' => $package['package_name'], '%status%' => (string) ($package['status'] ?? 'unknown')],
                    ['package' => $package['package_name'], 'status' => $package['status'] ?? null, 'action' => self::ACTION_PURGE],
                    MessageLevel::Warning,
                ),
            ]);
        }

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

    /**
     * @return list<string>
     */
    private function dependencies(?string $raw): array
    {
        if (null === $raw || '' === trim($raw) || '[]' === trim($raw)) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [$raw];
        }

        if (!is_array($decoded)) {
            return [$raw];
        }

        $dependencies = [];

        foreach ($decoded as $dependency) {
            if (is_scalar($dependency)) {
                $dependencies[] = (string) $dependency;

                continue;
            }

            if (is_array($dependency)) {
                $dependencies[] = implode(' ', array_filter(array_map(
                    static fn (mixed $part): ?string => is_scalar($part) ? (string) $part : null,
                    $dependency,
                )));
            }
        }

        return array_values(array_filter($dependencies, static fn (string $dependency): bool => '' !== trim($dependency)));
    }

    private function readManifest(string $basePath): ?Manifest
    {
        try {
            $path = $this->absolutePath($basePath, '.manifest');
        } catch (Throwable) {
            return null;
        }

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            return null;
        }

        $result = $this->manifestParser->parse($contents);
        $manifest = $result->value();

        return $manifest instanceof Manifest ? $manifest : null;
    }

    private function readReadme(string $basePath): ?string
    {
        try {
            $path = $this->absolutePath($basePath, 'README.md');
        } catch (Throwable) {
            return null;
        }

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) && '' !== trim($contents) ? $contents : null;
    }

    private function previewImageDataUri(string $basePath, ?string $imagePath): ?string
    {
        if (null === $imagePath || '' === trim($imagePath)) {
            return null;
        }

        try {
            $path = $this->absolutePath($basePath, $imagePath);
        } catch (Throwable) {
            return null;
        }

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $size = filesize($path);

        if (false === $size || $size > 2_000_000) {
            return null;
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            return null;
        }

        $mime = $this->imageMimeType($path);

        if (null === $mime) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    private function sourceUrl(?string $source, ?string $channel): ?string
    {
        if (null === $source || '' === trim($source)) {
            return null;
        }

        $source = preg_replace('/\.git$/', '', trim($source)) ?? trim($source);
        $safeSource = $this->safeExternalUrl($source);

        if (null === $safeSource) {
            return null;
        }

        if (null === $channel || '' === trim($channel)) {
            return $safeSource;
        }

        if (1 === preg_match('#^https://github\.com/[^/\s]+/[^/\s]+$#', $safeSource)) {
            return rtrim($safeSource, '/').'/tree/'.rawurlencode(trim($channel));
        }

        return $safeSource;
    }

    private function safeExternalUrl(?string $url): ?string
    {
        if (null === $url || '' === trim($url)) {
            return null;
        }

        $url = trim($url);

        if (1 === preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return null;
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = $parts['host'] ?? null;

        return is_string($host) && '' !== trim($host) ? $url : null;
    }

    private function absolutePath(string $basePath, string $relativePath): string
    {
        $basePath = '.' === $basePath ? '' : trim($basePath, '/');
        $relativePath = trim($relativePath, '/');
        $path = '' === $basePath ? $relativePath : $basePath.'/'.$relativePath;

        return rtrim($this->kernel->getProjectDir(), '/').'/'.$this->pathGuard->relativePath($path);
    }

    private function imageMimeType(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'gif' => 'image/gif',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => null,
        };
    }
}
