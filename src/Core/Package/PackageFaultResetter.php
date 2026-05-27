<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Filesystem\PathGuard;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Manifest\ManifestSpec;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final readonly class PackageFaultResetter
{
    private PackageSpec $validationSpec;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $projectDir,
        private WorkflowResultMessageReporterInterface $messageReporter,
        private string $environment = 'test',
        private PackageDiscovery $discovery = new PackageDiscovery(),
        private PackageValidator $validator = new PackageValidator(),
        private PathGuard $pathGuard = new PathGuard(),
        ?PackageSpec $validationSpec = null,
    ) {
        $this->validationSpec = $validationSpec ?? PackageSpec::create()
            ->withInventoryDepth(4)
            ->withLintingChecks();
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function resetFault(string $packageName): WorkflowResult
    {
        return $this->report($this->doResetFault($packageName), ['package' => $packageName]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function doResetFault(string $packageName): WorkflowResult
    {
        $package = $this->package($packageName);

        if (null === $package) {
            return $this->packageNotFound($packageName);
        }

        if (ExtensionPackageStatus::Faulty !== $package->status()) {
            return $this->statusBlocked($package);
        }

        if (!$this->isManagedFilesystemPackage($package)) {
            return $this->statusBlocked($package, 'not_filesystem_package');
        }

        $candidateResult = $this->discoverCandidate($package);

        if (!$candidateResult->isSuccess()) {
            return WorkflowResult::invalid($candidateResult->issues(), [
                'package' => $packageName,
                'path' => $package->path(),
                'discovery_context' => $candidateResult->context(),
            ], $candidateResult->messages());
        }

        $candidate = $candidateResult->value();
        $validation = $this->validator->validate($candidate, $this->validationSpec);

        if (!$validation->isSuccess()) {
            return WorkflowResult::invalid($validation->issues(), [
                'package' => $packageName,
                'path' => $package->path(),
                'validation_context' => $validation->context(),
            ], $validation->messages());
        }

        try {
            $path = $this->relativePackagePath($candidate);
            $scopes = PackageScope::fromManifestValue((string) $candidate->manifest()->get('PACKAGE_SCOPE', ''));
        } catch (InvalidArgumentException) {
            return WorkflowResult::invalid([
                Message::create(
                    MessageCode::PACKAGE_IDENTIFIER_INVALID,
                    MessageKey::PACKAGE_IDENTIFIER_INVALID,
                    ['%identifier%' => $packageName],
                    ['package' => $packageName, 'path' => $package->path()],
                    MessageLevel::Error,
                ),
            ]);
        }

        $package->syncRegistryState(
            $scopes,
            $path,
            $this->manifestString($candidate, 'PACKAGE_VERSION'),
            $this->metadata($candidate, $package),
        );
        $this->entityManager->flush();

        $change = [
            'package' => $packageName,
            'action' => 'fault_reset',
            'status' => ExtensionPackageStatus::Inactive->value,
        ];

        return WorkflowResult::success([
            'package' => $packageName,
            'changes' => [$change],
            'asset_rebuild' => false,
        ], [
            'package' => $packageName,
            'path' => $package->path(),
            'changes' => [$change],
        ], [
            ...$validation->messages(),
            Message::create(
                MessageCode::PACKAGE_LIFECYCLE_FAULT_RESET,
                MessageKey::PACKAGE_LIFECYCLE_FAULT_RESET,
                ['%package%' => $packageName],
                ['package' => $packageName, 'path' => $package->path()],
                MessageLevel::Success,
            ),
        ]);
    }

    private function package(string $packageName): ?ExtensionPackage
    {
        $package = $this->entityManager->getRepository(ExtensionPackage::class)->findOneBy([
            'packageName' => $packageName,
        ]);

        return $package instanceof ExtensionPackage ? $package : null;
    }

    /**
     * @return WorkflowResult<PackageCandidate>
     */
    private function discoverCandidate(ExtensionPackage $package): WorkflowResult
    {
        $source = PackageSource::single('package', $package->path(), $this->packageManifestSpec());
        $discovery = $this->discovery->discoverSources($this->projectDir, [$source]);

        if (!$discovery->isSuccess()) {
            return WorkflowResult::invalid($discovery->issues(), [
                'package' => $package->packageName(),
                'path' => $package->path(),
                'discovery_context' => $discovery->context(),
            ], $discovery->messages());
        }

        $candidate = $discovery->value()[0] ?? null;

        if (!$candidate instanceof PackageCandidate) {
            $manifestPath = $package->path().'/.manifest';

            return WorkflowResult::invalid([
                Message::create(
                    MessageCode::PACKAGE_REQUIRED_FILE_MISSING,
                    MessageKey::PACKAGE_REQUIRED_FILE_MISSING,
                    ['%path%' => $manifestPath],
                    ['package' => $package->packageName(), 'path' => $manifestPath],
                    MessageLevel::Error,
                ),
            ]);
        }

        return WorkflowResult::success($candidate, [
            'package' => $package->packageName(),
            'path' => $package->path(),
            'environment' => $this->environment,
        ], $discovery->messages());
    }

    private function isManagedFilesystemPackage(ExtensionPackage $package): bool
    {
        if (!$this->pathGuard->isRelativePath($package->path())) {
            return false;
        }

        $path = $this->pathGuard->relativePath($package->path());

        return 'packages' !== $path && str_starts_with($path, 'packages/');
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
     * @return array<string, mixed>
     */
    private function metadata(PackageCandidate $candidate, ExtensionPackage $package): array
    {
        return [
            'registry_state' => 'available',
            'manifest' => $candidate->manifest()->all(),
            'display_name' => $candidate->manifest()->get('PACKAGE_NAME'),
            'description' => $candidate->manifest()->get('PACKAGE_DESCRIPTION'),
            'dependencies' => $candidate->manifest()->get('PACKAGE_DEPENDENCIES'),
            'validation' => [
                'issue_count' => 0,
                'issues' => [],
            ],
            'last_fault' => [
                'recovered_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'metadata' => $this->faultMetadata($package),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function faultMetadata(ExtensionPackage $package): array
    {
        return array_intersect_key($package->metadata(), array_flip([
            'registry_state',
            'validation',
            'runtime_failure',
            'runtime_loader',
        ]));
    }

    private function manifestString(PackageCandidate $candidate, string $key): ?string
    {
        $value = $candidate->manifest()->get($key);

        return is_scalar($value) ? (string) $value : null;
    }

    private function packageManifestSpec(): ManifestSpec
    {
        return PackageManifestSpec::create();
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function packageNotFound(string $packageName): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::create(
                MessageCode::PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND,
                MessageKey::PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND,
                ['%package%' => $packageName],
                ['package' => $packageName],
                MessageLevel::Warning,
            ),
        ]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function statusBlocked(ExtensionPackage $package, string $reason = 'status'): WorkflowResult
    {
        return WorkflowResult::blocked([
            Message::create(
                MessageCode::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
            MessageKey::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                ['%package%' => $package->packageName(), '%status%' => $package->status()->value],
                ['package' => $package->packageName(), 'status' => $package->status()->value, 'reason' => $reason],
                MessageLevel::Warning,
            ),
        ]);
    }

    private function report(WorkflowResult $result, array $context = []): WorkflowResult
    {
        return $this->messageReporter->report($result, [
            ...$context,
            'operation' => 'package.fault_reset',
        ]);
    }
}
