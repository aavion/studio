<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Filesystem\PathGuard;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final readonly class PackageRegistryHandler
{
    private PackageSpec $validationSpec;
    private PackageDependencyResolver $dependencyResolver;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $projectDir,
        private PackageValidator $validator = new PackageValidator(),
        private PathGuard $pathGuard = new PathGuard(),
        private ?PackageAssetRebuildDispatcher $assetRebuildDispatcher = null,
        private string $environment = 'test',
        ?PackageSpec $validationSpec = null,
        ?PackageDependencyResolver $dependencyResolver = null,
    ) {
        $this->validationSpec = $validationSpec ?? PackageSpec::create()
            ->withInventoryDepth(4)
            ->withLintingChecks();
        $this->dependencyResolver = $dependencyResolver ?? new PackageDependencyResolver($entityManager);
    }

    /**
     * @param iterable<PackageCandidate> $candidates
     *
     * @return WorkflowResult<list<array{package: string, action: string, status: string}>>
     */
    public function synchronize(iterable $candidates): WorkflowResult
    {
        $packages = $this->indexedPackages();
        $seen = [];
        $changes = [];
        $messages = [];
        $issues = [];
        $assetRebuildTriggers = [];

        foreach ($candidates as $candidate) {
            if ('package' !== $candidate->source()->name()) {
                continue;
            }

            try {
                $packageName = $this->packageName($candidate);
                $path = $this->relativePackagePath($candidate);
                $scopes = PackageScope::fromManifestValue((string) $candidate->manifest()->get('PACKAGE_SCOPE', ''));
            } catch (InvalidArgumentException) {
                $issues[] = Message::create(
                    MessageCode::PACKAGE_IDENTIFIER_INVALID,
                    MessageKey::PACKAGE_IDENTIFIER_INVALID,
                    ['%identifier%' => basename($candidate->directory())],
                    ['path' => $candidate->directory(), 'source' => $candidate->source()->name()],
                    MessageLevel::Error,
                );

                continue;
            }

            $seen[$packageName] = true;
            $isNew = !isset($packages[$packageName]);
            $package = $packages[$packageName] ?? new ExtensionPackage(
                $this->uuid(),
                $scopes,
                $packageName,
                $path,
            );

            if ($isNew) {
                $this->entityManager->persist($package);
                $packages[$packageName] = $package;
            }

            $manifestVersion = $candidate->manifest()->get('PACKAGE_VERSION');

            if (!$isNew && $this->keepsExistingFaultyState($package, $path, $manifestVersion)) {
                continue;
            }

            $validation = $this->validator->validate($candidate, $this->validationSpec);

            if (!$validation->isSuccess()) {
                $wasActive = ExtensionPackageStatus::Active === $package->status();
                $changed = $package->markFaulty($path, $manifestVersion, $this->metadata($candidate, 'faulty', $validation->issues()));

                if ($changed || $isNew) {
                    $changes[] = $this->change($packageName, 'faulty', ExtensionPackageStatus::Faulty);
                    $messages[] = Message::error(
                        MessageCode::PACKAGE_REGISTRY_PACKAGE_FAULTY,
                        MessageKey::PACKAGE_REGISTRY_PACKAGE_FAULTY,
                        ['%package%' => $packageName],
                        ['package' => $packageName, 'path' => $path, 'issue_count' => count($validation->issues())],
                    );
                }

                if ($changed && $wasActive) {
                    $assetRebuildTriggers[] = $this->assetRebuildTrigger($packageName, 'package_registry_faulty');
                    $dependentChanges = $this->deactivateActiveDependents($package, 'package_registry_faulty');
                    $changes = [...$changes, ...$dependentChanges['changes']];
                    $messages = [...$messages, ...$dependentChanges['messages']];
                    $assetRebuildTriggers = [...$assetRebuildTriggers, ...$dependentChanges['asset_rebuild_triggers']];
                }

                continue;
            }

            $changed = $package->syncRegistryState($scopes, $path, $manifestVersion, $this->metadata($candidate, 'available'));
            $action = $isNew ? 'registered' : ($changed ? 'updated' : 'unchanged');

            if ('unchanged' !== $action) {
                $changes[] = $this->change($packageName, $action, $package->status());
                $messages[] = Message::create(
                    'registered' === $action ? MessageCode::PACKAGE_REGISTRY_PACKAGE_REGISTERED : MessageCode::PACKAGE_REGISTRY_PACKAGE_UPDATED,
                    'registered' === $action ? MessageKey::PACKAGE_REGISTRY_PACKAGE_REGISTERED : MessageKey::PACKAGE_REGISTRY_PACKAGE_UPDATED,
                    ['%package%' => $packageName],
                    ['package' => $packageName, 'path' => $path, 'status' => $package->status()->value],
                    MessageLevel::Success,
                );
            }
        }

        foreach ($packages as $packageName => $package) {
            if (isset($seen[$packageName]) || !$this->isManagedFilesystemPackage($package)) {
                continue;
            }

            $wasActive = ExtensionPackageStatus::Active === $package->status();

            if ($package->markRemoved($this->removedMetadata($package))) {
                $changes[] = $this->change($packageName, 'removed', ExtensionPackageStatus::Removed);
                $messages[] = Message::error(
                    MessageCode::PACKAGE_REGISTRY_PACKAGE_REMOVED,
                    MessageKey::PACKAGE_REGISTRY_PACKAGE_REMOVED,
                    ['%package%' => $packageName],
                    ['package' => $packageName, 'path' => $package->path()],
                );

                if ($wasActive) {
                    $assetRebuildTriggers[] = $this->assetRebuildTrigger($packageName, 'package_registry_removed');
                    $dependentChanges = $this->deactivateActiveDependents($package, 'package_registry_removed');
                    $changes = [...$changes, ...$dependentChanges['changes']];
                    $messages = [...$messages, ...$dependentChanges['messages']];
                    $assetRebuildTriggers = [...$assetRebuildTriggers, ...$dependentChanges['asset_rebuild_triggers']];
                }
            }
        }

        if ([] !== $issues) {
            return WorkflowResult::invalid($issues, ['changes' => $changes], $messages);
        }

        $this->entityManager->flush();

        if ([] !== $assetRebuildTriggers) {
            $this->assetRebuildDispatcher?->dispatch($this->environment, 'package_registry_state_exit');
        }

        $messages[] = Message::create(
            MessageCode::PACKAGE_REGISTRY_SYNC_COMPLETED,
            MessageKey::PACKAGE_REGISTRY_SYNC_COMPLETED,
            ['%count%' => count($changes)],
            ['change_count' => count($changes), 'changes' => $changes],
            MessageLevel::Success,
        );

        return WorkflowResult::success($changes, [
            'change_count' => count($changes),
            'changes' => $changes,
        ], $messages);
    }

    /**
     * @return array{
     *     changes: list<array{package: string, action: string, status: string}>,
     *     messages: list<Message>,
     *     asset_rebuild_triggers: list<string>
     * }
     */
    private function deactivateActiveDependents(ExtensionPackage $package, string $trigger): array
    {
        $changes = [];
        $messages = [];
        $assetRebuildTriggers = [];

        foreach ($this->dependencyResolver->activeDependentsOf($package) as $dependent) {
            if (!$dependent->deactivate()) {
                continue;
            }

            $changes[] = $this->change($dependent->packageName(), 'deactivated', ExtensionPackageStatus::Inactive);
            $messages[] = Message::create(
                MessageCode::PACKAGE_LIFECYCLE_DEACTIVATED,
                MessageKey::PACKAGE_LIFECYCLE_DEACTIVATED,
                ['%package%' => $dependent->packageName()],
                ['package' => $dependent->packageName(), 'required_package' => $package->packageName()],
                MessageLevel::Success,
            );
            $assetRebuildTriggers[] = $this->assetRebuildTrigger($dependent->packageName(), $trigger);
        }

        return [
            'changes' => $changes,
            'messages' => $messages,
            'asset_rebuild_triggers' => $assetRebuildTriggers,
        ];
    }

    /**
     * @return array<string, ExtensionPackage>
     */
    private function indexedPackages(): array
    {
        $packages = [];

        foreach ($this->entityManager->getRepository(ExtensionPackage::class)->findAll() as $package) {
            if ($package instanceof ExtensionPackage) {
                $packages[$package->packageName()] = $package;
            }
        }

        return $packages;
    }

    private function packageName(PackageCandidate $candidate): string
    {
        $slug = trim((string) $candidate->manifest()->get('PACKAGE_SLUG', ''));

        if (!PackageManifestSpec::isValidSlug($slug)) {
            throw new InvalidArgumentException(sprintf('Package slug "%s" is invalid.', $slug));
        }

        return $this->pathGuard->relativePath($slug);
    }

    private function relativePackagePath(PackageCandidate $candidate): string
    {
        $projectDir = rtrim(str_replace('\\', '/', $this->projectDir), '/');
        $directory = str_replace('\\', '/', $candidate->directory());

        if (!str_starts_with($directory, $projectDir.'/')) {
            throw new InvalidArgumentException('Package candidate directory must be inside the project directory.');
        }

        return $this->pathGuard->relativePath(substr($directory, strlen($projectDir) + 1));
    }

    /**
     * @param list<Message> $issues
     *
     * @return array<string, mixed>
     */
    private function metadata(PackageCandidate $candidate, string $state, array $issues = []): array
    {
        return [
            'registry_state' => $state,
            'manifest' => $candidate->manifest()->all(),
            'slug' => $candidate->manifest()->get('PACKAGE_SLUG'),
            'display_name' => $candidate->manifest()->get('PACKAGE_NAME'),
            'author' => $candidate->manifest()->get('PACKAGE_AUTHOR'),
            'description' => $candidate->manifest()->get('PACKAGE_DESCRIPTION'),
            'dependencies' => $candidate->manifest()->get('PACKAGE_DEPENDENCIES'),
            'license' => $candidate->manifest()->get('PACKAGE_LICENSE'),
            'homepage' => $candidate->manifest()->get('PACKAGE_HOMEPAGE'),
            'source' => $candidate->manifest()->get('PACKAGE_SOURCE'),
            'channel' => $candidate->manifest()->get('PACKAGE_CHANNEL'),
            'image' => $candidate->manifest()->get('PACKAGE_IMAGE'),
            'validation' => [
                'issue_count' => count($issues),
                'issues' => array_map(static fn (Message $issue): array => $issue->toArray(), $issues),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function removedMetadata(ExtensionPackage $package): array
    {
        return [
            ...$package->metadata(),
            'registry_state' => 'removed',
            'removed_path' => $package->path(),
        ];
    }

    private function isManagedFilesystemPackage(ExtensionPackage $package): bool
    {
        if (!$this->pathGuard->isRelativePath($package->path())) {
            return false;
        }

        $path = $this->pathGuard->relativePath($package->path());

        return 'packages' !== $path && str_starts_with($path, 'packages/');
    }

    private function keepsExistingFaultyState(ExtensionPackage $package, string $path, ?string $manifestVersion): bool
    {
        return ExtensionPackageStatus::Faulty === $package->status()
            && $package->path() === $path
            && $package->manifestVersion() === $manifestVersion;
    }

    /**
     * @return array{package: string, action: string, status: string}
     */
    private function change(string $packageName, string $action, ExtensionPackageStatus $status): array
    {
        return [
            'package' => $packageName,
            'action' => $action,
            'status' => $status->value,
        ];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function assetRebuildTrigger(string $packageName, string $trigger): string
    {
        return $trigger.':'.$packageName;
    }
}
