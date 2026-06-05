<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

use App\Core\Id\UuidFactory;
use App\Core\Manifest\Manifest;
use App\Core\Manifest\ManifestParser;
use App\Core\Manifest\ManifestValidator;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageActivator;
use App\Core\Package\PackageCandidate;
use App\Core\Package\PackageDependencyResolver;
use App\Core\Package\PackageDiscoveryRunner;
use App\Core\Package\PackageManifestSpec;
use App\Core\Package\PackageScope;
use App\Core\Package\PackageSource;
use App\Core\Package\PackageSpec;
use App\Core\Package\PackageValidator;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;
use ZipArchive;

final readonly class PackageZipInstaller
{
    private const ZIP_UNIX_FILE_TYPE_MASK = 0o170000;
    private const ZIP_UNIX_SYMLINK_TYPE = 0o120000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PackageDiscoveryRunner $discoveryRunner,
        private PackageActivator $activator,
        private PackageDependencyResolver $dependencyResolver,
        private string $projectDir,
        private string $environment,
        private ManifestParser $manifestParser = new ManifestParser(),
        private ManifestValidator $manifestValidator = new ManifestValidator(),
        private PackageValidator $packageValidator = new PackageValidator(),
        private UuidFactory $uuidFactory = new UuidFactory(),
    ) {
    }

    /**
     * @return WorkflowResult<array{install_id: string, zip_path: string}>
     */
    public function stageUpload(?UploadedFile $file): WorkflowResult
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return WorkflowResult::invalid([
                Message::warning(
                    MessageCode::PACKAGE_INSTALL_UPLOAD_INVALID,
                    MessageKey::PACKAGE_INSTALL_UPLOAD_INVALID,
                    context: ['reason' => 'missing_or_invalid_upload'],
                ),
            ]);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ('zip' !== $extension) {
            return WorkflowResult::invalid([
                Message::warning(
                    MessageCode::PACKAGE_INSTALL_UPLOAD_INVALID,
                    MessageKey::PACKAGE_INSTALL_UPLOAD_INVALID,
                    context: ['reason' => 'unsupported_extension', 'extension' => $extension],
                ),
            ]);
        }

        $installId = bin2hex(random_bytes(12));
        $root = $this->installRoot($installId);
        $zipPath = $root.DIRECTORY_SEPARATOR.'upload.zip';

        try {
            $this->ensureDirectory($root);
            $file->move($root, 'upload.zip');
        } catch (Throwable $error) {
            return WorkflowResult::failed([
                Message::exception(
                    MessageCode::PACKAGE_INSTALL_UPLOAD_INVALID,
                    MessageKey::PACKAGE_INSTALL_UPLOAD_INVALID,
                    context: [
                        'install_id' => $installId,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                ),
            ]);
        }

        return WorkflowResult::success([
            'install_id' => $installId,
            'zip_path' => $this->relativePath($zipPath),
        ], [
            'install_id' => $installId,
            'zip_path' => $this->relativePath($zipPath),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    public function verify(array $payload): WorkflowResult
    {
        $installId = $this->payloadString($payload, 'install_id');
        if (null === $installId || 1 !== preg_match('/^[a-f0-9]{24}$/', $installId)) {
            return $this->invalidPayload('verify', array_keys($payload));
        }

        $root = $this->installRoot($installId);
        $zipPath = $root.DIRECTORY_SEPARATOR.'upload.zip';
        $stagePath = $root.DIRECTORY_SEPARATOR.'staged';

        if (!is_file($zipPath)) {
            return $this->invalidPayload('verify', ['install_id', 'upload_missing']);
        }

        $extract = $this->extractZip($zipPath, $stagePath);
        if (!$extract->isSuccess()) {
            return $extract;
        }

        $packageRoot = $this->packageRoot($stagePath);
        if (null === $packageRoot) {
            return WorkflowResult::invalid([
                Message::error(
                    MessageCode::PACKAGE_INSTALL_ROOT_INVALID,
                    MessageKey::PACKAGE_INSTALL_ROOT_INVALID,
                    context: ['install_id' => $installId, 'stage_path' => $this->relativePath($stagePath)],
                ),
            ]);
        }

        $manifest = $this->readManifest($packageRoot);
        if (!$manifest->isSuccess()) {
            return $manifest;
        }

        $manifestValue = $manifest->value();
        $validation = $this->manifestValidator->validate($manifestValue, PackageManifestSpec::create());
        if (!$validation->isSuccess()) {
            return WorkflowResult::invalid($validation->issues(), ['install_id' => $installId], $validation->messages());
        }

        $slug = trim((string) $manifestValue->get('PACKAGE_SLUG', ''));
        $name = trim((string) $manifestValue->get('PACKAGE_NAME', $slug));
        $version = trim((string) $manifestValue->get('PACKAGE_VERSION', ''));
        $scope = trim((string) $manifestValue->get('PACKAGE_SCOPE', ''));

        if ('system' === $slug || !PackageManifestSpec::isValidSlug($slug)) {
            return WorkflowResult::invalid([
                Message::warning(
                    MessageCode::PACKAGE_IDENTIFIER_INVALID,
                    MessageKey::PACKAGE_IDENTIFIER_INVALID,
                    ['%identifier%' => $slug],
                    ['install_id' => $installId, 'slug' => $slug],
                ),
            ]);
        }

        try {
            $scopes = PackageScope::fromManifestValue($scope);
        } catch (\InvalidArgumentException) {
            return WorkflowResult::invalid([
                Message::error(
                    MessageCode::PACKAGE_SCOPE_INVALID,
                    MessageKey::PACKAGE_SCOPE_INVALID,
                    ['%scope%' => $scope],
                    ['install_id' => $installId, 'slug' => $slug, 'scope' => $scope],
                ),
            ]);
        }

        $candidate = new PackageCandidate(
            PackageSource::single('package', '.', PackageManifestSpec::create()),
            $packageRoot,
            $packageRoot.DIRECTORY_SEPARATOR.'.manifest',
            $manifestValue,
        );
        $packageValidation = $this->packageValidator->validate($candidate, PackageSpec::create()->withInventoryDepth(4)->withLintingChecks());
        if (!$packageValidation->isSuccess()) {
            return WorkflowResult::invalid($packageValidation->issues(), [
                'install_id' => $installId,
                'slug' => $slug,
            ], $packageValidation->messages());
        }

        $existing = $this->package($slug);
        $wasActive = $existing instanceof ExtensionPackage && ExtensionPackageStatus::Active === $existing->status();
        $versionGate = $this->ensureInstallOrUpdateAllowed($manifestValue, $existing, $installId, $slug);
        if ($versionGate instanceof WorkflowResult) {
            return $versionGate;
        }

        $dependencyPreflight = null;
        $deactivationTargets = [];

        if ($wasActive) {
            $dependencyPreflight = $this->preflightReplacementDependencies($manifestValue, $slug, $scopes);

            if (!$dependencyPreflight->isSuccess()) {
                return WorkflowResult::blocked($dependencyPreflight->issues(), [
                    'install_id' => $installId,
                    'package' => $slug,
                    'dependencies' => $dependencyPreflight->context()['dependencies'] ?? [],
                ], [
                    ...$validation->messages(),
                    ...$packageValidation->messages(),
                    ...$dependencyPreflight->messages(),
                ]);
            }

            $deactivationPlan = $this->activator->planDeactivation($slug);

            if (!$deactivationPlan->isSuccess()) {
                return WorkflowResult::blocked($deactivationPlan->issues(), [
                    'install_id' => $installId,
                    'package' => $slug,
                    'deactivation_context' => $deactivationPlan->context(),
                ], [
                    ...$validation->messages(),
                    ...$packageValidation->messages(),
                    ...$dependencyPreflight->messages(),
                    ...$deactivationPlan->messages(),
                ]);
            }

            $deactivationTargets = $this->packageNameList($deactivationPlan->value()['deactivate'] ?? []);
        }

        $issues = [
            Message::info(
                MessageCode::PACKAGE_INSTALL_READY,
                MessageKey::PACKAGE_INSTALL_READY,
                ['%package%' => $name, '%version%' => $version],
                ['install_id' => $installId, 'package' => $slug, 'version' => $version],
            ),
        ];

        if ($existing instanceof ExtensionPackage) {
            $issues[] = Message::warning(
                MessageCode::PACKAGE_INSTALL_OVERWRITE,
                MessageKey::PACKAGE_INSTALL_OVERWRITE,
                ['%package%' => $slug],
                ['install_id' => $installId, 'package' => $slug, 'was_active' => $wasActive],
            );
        }

        return WorkflowResult::requiresReview([
            'install_id' => $installId,
            'package' => $slug,
            'name' => $name,
            'version' => $version,
            'was_active' => $wasActive,
            'dependencies' => $dependencyPreflight?->context()['dependencies'] ?? [],
            'deactivate' => $deactivationTargets,
        ], $issues, [
            'install_id' => $installId,
            'package' => $slug,
            'name' => $name,
            'version' => $version,
            'was_active' => $wasActive,
            'dependencies' => $dependencyPreflight?->context()['dependencies'] ?? [],
            'deactivate' => $deactivationTargets,
            'live_operation_continuation' => [
                'operation' => LiveOperationQueueFactory::PACKAGE_INSTALL_APPLY,
                'label' => sprintf('Install package %s', $slug),
                'payload' => [
                    'install_id' => $installId,
                    'package' => $slug,
                    'was_active' => $wasActive,
                    'trigger' => 'admin_ui',
                    'environment' => $this->environment,
                ],
            ],
        ], [
            ...$validation->messages(),
            ...$packageValidation->messages(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    public function apply(array $payload): WorkflowResult
    {
        $installId = $this->payloadString($payload, 'install_id');
        $slug = $this->payloadString($payload, 'package');

        if (null === $installId || null === $slug || !PackageManifestSpec::isValidSlug($slug)) {
            return $this->invalidPayload('apply', array_keys($payload));
        }

        $root = $this->installRoot($installId);
        $stagePath = $root.DIRECTORY_SEPARATOR.'staged';
        $packageRoot = $this->packageRoot($stagePath);

        if (null === $packageRoot) {
            return WorkflowResult::invalid([
                Message::error(
                    MessageCode::PACKAGE_INSTALL_ROOT_INVALID,
                    MessageKey::PACKAGE_INSTALL_ROOT_INVALID,
                    context: ['install_id' => $installId, 'package' => $slug],
                ),
            ]);
        }

        $symlink = $this->firstSymlinkPath($packageRoot);
        if (null !== $symlink) {
            return WorkflowResult::invalid([
                Message::warning(
                    MessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                    MessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                    context: [
                        'install_id' => $installId,
                        'reason' => 'symlink_entry',
                        'entry' => $this->relativePath($symlink),
                    ],
                ),
            ]);
        }

        $messages = [];
        $existing = $this->package($slug);
        $wasActive = $existing instanceof ExtensionPackage && ExtensionPackageStatus::Active === $existing->status();
        $previousStatus = $existing?->status();
        $target = $this->projectDir.DIRECTORY_SEPARATOR.'packages'.DIRECTORY_SEPARATOR.$slug;
        $prepared = $root.DIRECTORY_SEPARATOR.'prepared'.DIRECTORY_SEPARATOR.$slug;
        $backup = $root.DIRECTORY_SEPARATOR.'backup'.DIRECTORY_SEPARATOR.$slug;
        $deactivationTargets = [];
        $previousStatuses = $previousStatus instanceof ExtensionPackageStatus ? [$slug => $previousStatus] : [];

        $manifest = $this->readManifest($packageRoot);
        if (!$manifest->isSuccess()) {
            return $manifest;
        }

        $manifestValue = $manifest->value();
        try {
            $scopes = PackageScope::fromManifestValue((string) $manifestValue->get('PACKAGE_SCOPE', ''));
        } catch (\InvalidArgumentException) {
            return WorkflowResult::invalid([
                Message::error(
                    MessageCode::PACKAGE_SCOPE_INVALID,
                    MessageKey::PACKAGE_SCOPE_INVALID,
                    ['%scope%' => (string) $manifestValue->get('PACKAGE_SCOPE', '')],
                    ['install_id' => $installId, 'package' => $slug],
                ),
            ]);
        }

        $versionGate = $this->ensureInstallOrUpdateAllowed($manifestValue, $existing, $installId, $slug);
        if ($versionGate instanceof WorkflowResult) {
            return $versionGate;
        }

        if ($wasActive) {
            $dependencyPreflight = $this->preflightReplacementDependencies($manifestValue, $slug, $scopes);

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

            $deactivationTargets = $this->packageNameList($deactivationPlan->value()['deactivate'] ?? []);
            $previousStatuses = $this->statusSnapshots($deactivationTargets);
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
            $this->prepareReplacement($packageRoot, $prepared);
            $this->swapPreparedPackage($prepared, $target, $backup);
        } catch (Throwable $error) {
            $rollbackMessages = $this->restorePackageStatuses($previousStatuses);

            return WorkflowResult::failed([
                Message::exception(
                    MessageCode::OPERATION_EXCEPTION,
                    MessageKey::OPERATION_EXCEPTION,
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
            $rollbackMessages = $this->restorePreviousPackage($slug, $target, $backup, $previousStatuses);

            return WorkflowResult::failed($discovery->issues(), [
                'install_id' => $installId,
                'package' => $slug,
                'discovery_context' => $discovery->context(),
                'rolled_back' => [] === $rollbackMessages,
            ], [...$messages, ...$rollbackMessages]);
        }

        $installed = $this->package($slug);
        if ($installed instanceof ExtensionPackage && ExtensionPackageStatus::Active === $installed->status()) {
            $installed->restoreStatus(ExtensionPackageStatus::Inactive);
            $this->entityManager->flush();
        }

        $installed = $this->package($slug);
        if (!$this->isInstalledInactivePackage($installed, $manifestValue)) {
            $rollbackMessages = $this->restorePreviousPackage($slug, $target, $backup, $previousStatuses);
            $status = $installed?->status()->value;

            return WorkflowResult::failed([
                Message::error(
                    MessageCode::PACKAGE_REGISTRY_PACKAGE_FAULTY,
                    MessageKey::PACKAGE_REGISTRY_PACKAGE_FAULTY,
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
            $reactivationTargets = $this->reactivationOrder($slug, $deactivationTargets, $previousStatuses);
            $activation = $this->activator->activate($slug, $this->environment, rebuildAssets: [] === $reactivationTargets);
            $messages = [...$messages, ...$activation->messages()];

            if (!$activation->isSuccess()) {
                $rollbackMessages = $this->restorePreviousPackage($slug, $target, $backup, $previousStatuses);

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
                    $rollbackMessages = $this->restorePreviousPackage($slug, $target, $backup, $previousStatuses);

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

        $this->removePath($root);

        return WorkflowResult::success([
            'package' => $slug,
            'was_active' => $wasActive,
        ], [
            'package' => $slug,
            'was_active' => $wasActive,
        ], [
            ...$messages,
            Message::success(
                MessageKey::PACKAGE_INSTALL_COMPLETED,
                ['%package%' => $slug],
                ['package' => $slug, 'was_active' => $wasActive],
            ),
        ]);
    }

    /**
     * @param list<PackageScope> $scopes
     *
     * @return WorkflowResult<array{packages: list<ExtensionPackage>, dependencies: list<array<string, mixed>>}>
     */
    private function preflightReplacementDependencies(Manifest $manifest, string $slug, array $scopes): WorkflowResult
    {
        $version = trim((string) $manifest->get('PACKAGE_VERSION', ''));
        $package = new ExtensionPackage(
            $this->uuidFactory->generate(),
            $scopes,
            $slug,
            'packages/'.$slug,
            ExtensionPackageStatus::Inactive,
            ['manifest' => $manifest->all()],
            manifestVersion: '' !== $version ? $version : null,
            installedVersion: '' !== $version ? $version : null,
        );

        return $this->dependencyResolver->resolve($package);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>|null
     */
    private function ensureInstallOrUpdateAllowed(
        Manifest $manifest,
        ?ExtensionPackage $existing,
        string $installId,
        string $slug,
    ): ?WorkflowResult {
        if (!$existing instanceof ExtensionPackage) {
            return null;
        }

        $version = trim((string) $manifest->get('PACKAGE_VERSION', ''));
        $installedVersion = $this->installedPackageVersion($existing);

        if (null === $installedVersion || '' === $version || version_compare($version, $installedVersion, '>')) {
            return null;
        }

        if (
            0 === version_compare($version, $installedVersion)
            && in_array($existing->status(), [ExtensionPackageStatus::Faulty, ExtensionPackageStatus::Removed], true)
        ) {
            return null;
        }

        return WorkflowResult::blocked([
            Message::warning(
                MessageCode::PACKAGE_INSTALL_VERSION_BLOCKED,
                MessageKey::PACKAGE_INSTALL_VERSION_BLOCKED,
                [
                    '%package%' => $slug,
                    '%version%' => '' !== $version ? $version : 'unknown',
                    '%installed_version%' => $installedVersion ?? 'unknown',
                ],
                [
                    'install_id' => $installId,
                    'package' => $slug,
                    'version' => '' !== $version ? $version : null,
                    'installed_version' => $installedVersion,
                    'status' => $existing->status()->value,
                ],
            ),
        ], [
            'install_id' => $installId,
            'package' => $slug,
            'version' => '' !== $version ? $version : null,
            'installed_version' => $installedVersion,
            'status' => $existing->status()->value,
        ]);
    }

    private function installedPackageVersion(ExtensionPackage $package): ?string
    {
        $version = $package->installedVersion() ?? $package->manifestVersion();

        return is_string($version) && '' !== trim($version) ? trim($version) : null;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private function packageNameList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $packageName): bool => is_string($packageName) && '' !== trim($packageName),
        ));
    }

    /**
     * @param list<string> $packageNames
     *
     * @return array<string, ExtensionPackageStatus>
     */
    private function statusSnapshots(array $packageNames): array
    {
        $snapshots = [];

        foreach (array_unique($packageNames) as $packageName) {
            $package = $this->package($packageName);

            if ($package instanceof ExtensionPackage) {
                $snapshots[$packageName] = $package->status();
            }
        }

        return $snapshots;
    }

    /**
     * @param array<string, ExtensionPackageStatus> $statuses
     *
     * @return list<Message>
     */
    private function restorePackageStatuses(array $statuses): array
    {
        try {
            foreach ($statuses as $packageName => $status) {
                $package = $this->package($packageName);

                if ($package instanceof ExtensionPackage) {
                    $package->restoreStatus($status);
                }
            }

            $this->entityManager->flush();
        } catch (Throwable $error) {
            return [
                Message::exception(
                    MessageCode::OPERATION_EXCEPTION,
                    MessageKey::OPERATION_EXCEPTION,
                    context: [
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                        'rollback' => true,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @param array<string, ExtensionPackageStatus> $previousStatuses
     *
     * @return list<string>
     */
    private function reactivationOrder(string $slug, array $deactivationTargets, array $previousStatuses): array
    {
        $targets = [];

        foreach (array_reverse($deactivationTargets) as $packageName) {
            if (
                $slug === $packageName
                || ExtensionPackageStatus::Active !== ($previousStatuses[$packageName] ?? null)
            ) {
                continue;
            }

            $targets[$packageName] = $packageName;
        }

        return array_values($targets);
    }

    private function isInstalledInactivePackage(?ExtensionPackage $package, Manifest $manifest): bool
    {
        if (!$package instanceof ExtensionPackage || ExtensionPackageStatus::Inactive !== $package->status()) {
            return false;
        }

        $expectedVersion = trim((string) $manifest->get('PACKAGE_VERSION', ''));

        return '' === $expectedVersion || $package->manifestVersion() === $expectedVersion;
    }

    private function prepareReplacement(string $packageRoot, string $prepared): void
    {
        $this->removePath($prepared);
        $this->ensureDirectory(dirname($prepared));
        $this->copyDirectory($packageRoot, $prepared);
    }

    private function swapPreparedPackage(string $prepared, string $target, string $backup): void
    {
        $this->removePath($backup);
        $this->ensureDirectory(dirname($backup));

        if ($this->pathExists($target)) {
            $this->movePath($target, $backup);
        }

        try {
            $this->ensureDirectory(dirname($target));
            $this->movePath($prepared, $target);
        } catch (Throwable $error) {
            $this->removePath($target);

            if ($this->pathExists($backup)) {
                $this->movePath($backup, $target);
            }

            throw $error;
        }
    }

    /**
     * @param array<string, ExtensionPackageStatus> $previousStatuses
     *
     * @return list<Message>
     */
    private function restorePreviousPackage(
        string $slug,
        string $target,
        string $backup,
        array $previousStatuses,
    ): array {
        try {
            $this->removePath($target);

            if ($this->pathExists($backup)) {
                $this->ensureDirectory(dirname($target));
                $this->movePath($backup, $target);
            }

            $discoveryMessages = [];
            if ($this->pathExists($target)) {
                $rollbackDiscovery = ($this->discoveryRunner)('package_install_rollback');
                if (!$rollbackDiscovery->isSuccess()) {
                    $discoveryMessages = [...$rollbackDiscovery->messages(), ...$rollbackDiscovery->issues()];
                }
            }

            $statusMessages = $this->restorePackageStatuses($previousStatuses);
            if ([] !== $statusMessages) {
                return [...$discoveryMessages, ...$statusMessages];
            }

            if ([] !== $discoveryMessages) {
                return $discoveryMessages;
            }
        } catch (Throwable $error) {
            return [
                Message::exception(
                    MessageCode::OPERATION_EXCEPTION,
                    MessageKey::OPERATION_EXCEPTION,
                    context: [
                        'package' => $slug,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                        'rollback' => true,
                    ],
                ),
            ];
        }

        return [];
    }

    private function movePath(string $source, string $target): void
    {
        if (@rename($source, $target)) {
            return;
        }

        if (is_dir($source)) {
            $this->copyDirectory($source, $target);
            $this->removePath($source);

            return;
        }

        $this->ensureDirectory(dirname($target));
        if (!copy($source, $target)) {
            throw new \RuntimeException(sprintf('Path "%s" could not be moved.', basename($source)));
        }

        unlink($source);
    }

    private function pathExists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    private function package(string $packageName): ?ExtensionPackage
    {
        $package = $this->entityManager->getRepository(ExtensionPackage::class)->findOneBy([
            'packageName' => $packageName,
        ]);

        return $package instanceof ExtensionPackage ? $package : null;
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function extractZip(string $zipPath, string $stagePath): WorkflowResult
    {
        if (!class_exists(ZipArchive::class)) {
            return WorkflowResult::failed([
                Message::error(
                    MessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                    MessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                    context: ['reason' => 'zip_extension_missing'],
                ),
            ]);
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);

        if (true !== $opened) {
            return WorkflowResult::invalid([
                Message::warning(
                    MessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                    MessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                    context: ['reason' => 'open_failed', 'zip_error' => $opened],
                ),
            ]);
        }

        try {
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || $this->unsafeZipEntry($name)) {
                    return WorkflowResult::invalid([
                        Message::warning(
                            MessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                            MessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                            context: ['reason' => 'unsafe_entry', 'entry' => $name],
                        ),
                    ]);
                }

                if ($this->symlinkZipEntry($zip, $index)) {
                    return WorkflowResult::invalid([
                        Message::warning(
                            MessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                            MessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                            context: ['reason' => 'symlink_entry', 'entry' => $name],
                        ),
                    ]);
                }
            }

            $this->removePath($stagePath);
            $this->ensureDirectory($stagePath);

            if (!$zip->extractTo($stagePath)) {
                return WorkflowResult::failed([
                    Message::error(
                        MessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                        MessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                        context: ['reason' => 'extract_failed', 'zip_path' => $this->relativePath($zipPath)],
                    ),
                ]);
            }

            $symlink = $this->firstSymlinkPath($stagePath);
            if (null !== $symlink) {
                return WorkflowResult::invalid([
                    Message::warning(
                        MessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                        MessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                        context: [
                            'reason' => 'symlink_entry',
                            'entry' => $this->relativePath($symlink),
                        ],
                    ),
                ]);
            }
        } finally {
            $zip->close();
        }

        return WorkflowResult::success([
            'stage_path' => $this->relativePath($stagePath),
        ]);
    }

    /**
     * @return WorkflowResult<Manifest>
     */
    private function readManifest(string $packageRoot): WorkflowResult
    {
        $path = $packageRoot.DIRECTORY_SEPARATOR.'.manifest';
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (!is_string($contents)) {
            return WorkflowResult::invalid([
                Message::error(
                    MessageCode::PACKAGE_MANIFEST_UNREADABLE,
                    MessageKey::PACKAGE_MANIFEST_UNREADABLE,
                    ['%path%' => $this->relativePath($path)],
                    ['path' => $this->relativePath($path)],
                ),
            ]);
        }

        return $this->manifestParser->parse($contents);
    }

    private function packageRoot(string $stagePath): ?string
    {
        if (is_file($stagePath.DIRECTORY_SEPARATOR.'.manifest')) {
            return $stagePath;
        }

        $children = array_values(array_filter(scandir($stagePath) ?: [], static fn (string $entry): bool => !in_array($entry, ['.', '..', '__MACOSX'], true)));

        if (1 !== count($children)) {
            return null;
        }

        $child = $stagePath.DIRECTORY_SEPARATOR.$children[0];

        return is_dir($child) && is_file($child.DIRECTORY_SEPARATOR.'.manifest') ? $child : null;
    }

    private function installRoot(string $installId): string
    {
        return $this->projectDir.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.$this->environment.DIRECTORY_SEPARATOR.'package-installs'.DIRECTORY_SEPARATOR.$installId;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Directory "%s" could not be created.', $path));
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        $this->ensureDirectory($target);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $relative = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target.DIRECTORY_SEPARATOR.$relative;

            if ($item->isLink()) {
                throw new \RuntimeException(sprintf('Symlink "%s" must not be copied into a package.', $relative));
            }

            if ($item->isDir()) {
                $this->ensureDirectory($destination);
                continue;
            }

            $this->ensureDirectory(dirname($destination));
            if (!copy($item->getPathname(), $destination)) {
                throw new \RuntimeException(sprintf('File "%s" could not be copied.', $relative));
            }
        }
    }

    private function removePath(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            $this->removeFileOrLink($path);

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $item->isDir() && !$item->isLink()
                ? rmdir($item->getPathname())
                : $this->removeFileOrLink($item->getPathname());
        }

        rmdir($path);
    }

    private function removeFileOrLink(string $path): void
    {
        if ('\\' === DIRECTORY_SEPARATOR && @rmdir($path)) {
            return;
        }

        @unlink($path);
    }

    private function symlinkZipEntry(ZipArchive $zip, int $index): bool
    {
        if (!method_exists($zip, 'getExternalAttributesIndex')) {
            return false;
        }

        $operatingSystem = 0;
        $attributes = 0;

        if (!$zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
            return false;
        }

        $mode = ($attributes >> 16) & self::ZIP_UNIX_FILE_TYPE_MASK;

        return self::ZIP_UNIX_SYMLINK_TYPE === $mode;
    }

    private function firstSymlinkPath(string $root): ?string
    {
        if (is_link($root)) {
            return $root;
        }

        if (!is_dir($root)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo && $item->isLink()) {
                return $item->getPathname();
            }
        }

        return null;
    }

    private function unsafeZipEntry(string $entry): bool
    {
        $normalized = str_replace('\\', '/', $entry);

        return str_starts_with($normalized, '/')
            || str_contains($normalized, '../')
            || str_starts_with($normalized, '../')
            || str_contains($normalized, "\0");
    }

    /**
     * @param list<string|int> $payloadKeys
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    private function invalidPayload(string $stage, array $payloadKeys): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::warning(
                MessageCode::E_INVALID_ARGUMENT,
                MessageKey::OPERATION_INVALID_PAYLOAD,
                ['%operation%' => 'package.install.'.$stage],
                ['operation' => 'package.install.'.$stage, 'payload_keys' => array_values($payloadKeys)],
            ),
        ], ['operation' => 'package.install.'.$stage, 'payload_keys' => array_values($payloadKeys)]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    private function relativePath(string $path): string
    {
        $projectDir = rtrim(str_replace('\\', '/', $this->projectDir), '/');
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $projectDir.'/') ? substr($path, strlen($projectDir) + 1) : $path;
    }
}
