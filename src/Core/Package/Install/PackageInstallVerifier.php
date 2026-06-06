<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

use App\Core\Manifest\ManifestValidator;
use App\Core\Message\Message;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageActivator;
use App\Core\Package\PackageCandidate;
use App\Core\Package\PackageManifestSpec;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Core\Package\PackageScope;
use App\Core\Package\PackageSource;
use App\Core\Package\PackageSpec;
use App\Core\Package\PackageValidator;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;

final readonly class PackageInstallVerifier
{
    public function __construct(
        private PackageActivator $activator,
        private PackageZipExtractor $zipExtractor,
        private PackageInstallFilesystem $filesystem,
        private PackageInstallPayload $payloadReader,
        private PackageInstallStageReader $stageReader,
        private PackageInstallRegistry $registry,
        private PackageInstallVersionGuard $versionGuard,
        private PackageReplacementPreflight $replacementPreflight,
        private string $environment,
        private ManifestValidator $manifestValidator = new ManifestValidator(),
        private PackageValidator $packageValidator = new PackageValidator(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    public function verify(array $payload): WorkflowResult
    {
        $installId = $this->payloadReader->string($payload, 'install_id');
        if (null === $installId || 1 !== preg_match('/^[a-f0-9]{24}$/', $installId)) {
            return $this->payloadReader->invalid('verify', array_keys($payload));
        }

        $root = $this->filesystem->installRoot($this->environment, $installId);
        $zipPath = $root.DIRECTORY_SEPARATOR.'upload.zip';
        $stagePath = $root.DIRECTORY_SEPARATOR.'staged';

        if (!is_file($zipPath)) {
            return $this->invalidPayload('verify', ['install_id', 'upload_missing']);
        }

        $extract = $this->zipExtractor->extract($zipPath, $stagePath);
        if (!$extract->isSuccess()) {
            return $extract;
        }

        $packageRoot = $this->stageReader->packageRoot($stagePath);
        if (null === $packageRoot) {
            return WorkflowResult::invalid([
                Message::error(
                    PackageMessageCode::PACKAGE_INSTALL_ROOT_INVALID,
                    PackageMessageKey::PACKAGE_INSTALL_ROOT_INVALID,
                    context: ['install_id' => $installId, 'stage_path' => $this->filesystem->relativePath($stagePath)],
                ),
            ]);
        }

        $manifest = $this->stageReader->readManifest($packageRoot);
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
                    PackageMessageCode::PACKAGE_IDENTIFIER_INVALID,
                    PackageMessageKey::PACKAGE_IDENTIFIER_INVALID,
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
                    PackageMessageCode::PACKAGE_SCOPE_INVALID,
                    PackageMessageKey::PACKAGE_SCOPE_INVALID,
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

        $existing = $this->registry->package($slug);
        $wasActive = $existing instanceof ExtensionPackage && ExtensionPackageStatus::Active === $existing->status();
        $versionGate = $this->versionGuard->ensureAllowed($manifestValue, $existing, $installId, $slug);
        if ($versionGate instanceof WorkflowResult) {
            return $versionGate;
        }

        $dependencyPreflight = null;
        $deactivationTargets = [];

        if ($wasActive) {
            $dependencyPreflight = $this->replacementPreflight->dependencies($manifestValue, $slug, $scopes);

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

            $deactivationTargets = $this->replacementPreflight->packageNameList($deactivationPlan->value()['deactivate'] ?? []);
        }

        $issues = [
            Message::info(
                PackageMessageCode::PACKAGE_INSTALL_READY,
                PackageMessageKey::PACKAGE_INSTALL_READY,
                ['%package%' => $name, '%version%' => $version],
                ['install_id' => $installId, 'package' => $slug, 'version' => $version],
            ),
        ];

        if ($existing instanceof ExtensionPackage) {
            $issues[] = Message::warning(
                PackageMessageCode::PACKAGE_INSTALL_OVERWRITE,
                PackageMessageKey::PACKAGE_INSTALL_OVERWRITE,
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
}
