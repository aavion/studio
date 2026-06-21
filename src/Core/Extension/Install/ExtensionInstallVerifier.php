<?php

declare(strict_types=1);

namespace App\Core\Extension\Install;

use App\Core\Manifest\ManifestValidator;
use App\Core\Message\Message;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\ExtensionActivator;
use App\Core\Extension\ExtensionCandidate;
use App\Core\Extension\ExtensionManifestSpec;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionSource;
use App\Core\Extension\ExtensionSpec;
use App\Core\Extension\ExtensionValidator;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;

final readonly class ExtensionInstallVerifier
{
    public function __construct(
        private ExtensionActivator $activator,
        private ExtensionZipExtractor $zipExtractor,
        private ExtensionInstallFilesystem $filesystem,
        private ExtensionInstallPayload $payloadReader,
        private ExtensionInstallStageReader $stageReader,
        private ExtensionInstallRegistry $registry,
        private ExtensionInstallVersionGuard $versionGuard,
        private ExtensionReplacementPreflight $replacementPreflight,
        private string $environment,
        private ManifestValidator $manifestValidator = new ManifestValidator(),
        private ExtensionValidator $extensionValidator = new ExtensionValidator(),
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
        if (null === $installId || !$this->filesystem->isValidInstallId($installId)) {
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

        $extensionRoot = $this->stageReader->extensionRoot($stagePath);
        if (null === $extensionRoot) {
            return WorkflowResult::invalid([
                Message::error(
                    ExtensionMessageCode::EXTENSION_INSTALL_ROOT_INVALID,
                    ExtensionMessageKey::EXTENSION_INSTALL_ROOT_INVALID,
                    context: ['install_id' => $installId, 'stage_path' => $this->filesystem->relativePath($stagePath)],
                ),
            ]);
        }

        $manifest = $this->stageReader->readManifest($extensionRoot);
        if (!$manifest->isSuccess()) {
            return $manifest;
        }

        $manifestValue = $manifest->value();
        $validation = $this->manifestValidator->validate($manifestValue, ExtensionManifestSpec::create());
        if (!$validation->isSuccess()) {
            return WorkflowResult::invalid($validation->issues(), ['install_id' => $installId], $validation->messages());
        }

        $slug = trim((string) $manifestValue->get('EXTENSION_SLUG', ''));
        $name = trim((string) $manifestValue->get('EXTENSION_NAME', $slug));
        $version = trim((string) $manifestValue->get('EXTENSION_VERSION', ''));
        $scope = trim((string) $manifestValue->get('EXTENSION_SCOPE', ''));

        if ('system' === $slug || !ExtensionManifestSpec::isValidSlug($slug)) {
            return WorkflowResult::invalid([
                Message::warning(
                    ExtensionMessageCode::EXTENSION_IDENTIFIER_INVALID,
                    ExtensionMessageKey::EXTENSION_IDENTIFIER_INVALID,
                    ['%identifier%' => $slug],
                    ['install_id' => $installId, 'slug' => $slug],
                ),
            ]);
        }

        try {
            $scopes = ExtensionScope::fromManifestValue($scope);
        } catch (\InvalidArgumentException) {
            return WorkflowResult::invalid([
                Message::error(
                    ExtensionMessageCode::EXTENSION_SCOPE_INVALID,
                    ExtensionMessageKey::EXTENSION_SCOPE_INVALID,
                    ['%scope%' => $scope],
                    ['install_id' => $installId, 'slug' => $slug, 'scope' => $scope],
                ),
            ]);
        }

        $candidate = new ExtensionCandidate(
            ExtensionSource::single('extension', '.', ExtensionManifestSpec::create()),
            $extensionRoot,
            $extensionRoot.DIRECTORY_SEPARATOR.'.manifest',
            $manifestValue,
        );
        $extensionValidation = $this->extensionValidator->validate(
            $candidate,
            ExtensionSpec::create()
                ->withInventoryDepth(PHP_INT_MAX)
                ->withLintingChecks()
                ->withDirectorySlugMatch(false),
        );
        if (!$extensionValidation->isSuccess()) {
            return WorkflowResult::invalid($extensionValidation->issues(), [
                'install_id' => $installId,
                'slug' => $slug,
            ], $extensionValidation->messages());
        }

        $existing = $this->registry->extension($slug);
        $wasActive = $existing instanceof Extension && ExtensionStatus::Active === $existing->status();
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
                    'extension' => $slug,
                    'dependencies' => $dependencyPreflight->context()['dependencies'] ?? [],
                ], [
                    ...$validation->messages(),
                    ...$extensionValidation->messages(),
                    ...$dependencyPreflight->messages(),
                ]);
            }
        }

        if ($existing instanceof Extension && ExtensionStatus::Removed !== $existing->status()) {
            $deactivationPlan = $this->activator->planDeactivation($slug);

            if (!$deactivationPlan->isSuccess()) {
                return WorkflowResult::blocked($deactivationPlan->issues(), [
                    'install_id' => $installId,
                    'extension' => $slug,
                    'deactivation_context' => $deactivationPlan->context(),
                ], [
                    ...$validation->messages(),
                    ...$extensionValidation->messages(),
                    ...($dependencyPreflight?->messages() ?? []),
                    ...$deactivationPlan->messages(),
                ]);
            }

            $deactivationTargets = $this->replacementPreflight->extensionNameList($deactivationPlan->value()['deactivate'] ?? []);
        }

        $issues = [
            Message::info(
                ExtensionMessageCode::EXTENSION_INSTALL_READY,
                ExtensionMessageKey::EXTENSION_INSTALL_READY,
                ['%extension%' => $name, '%version%' => $version],
                ['install_id' => $installId, 'extension' => $slug, 'version' => $version],
            ),
        ];

        if ($existing instanceof Extension) {
            $issues[] = Message::warning(
                ExtensionMessageCode::EXTENSION_INSTALL_OVERWRITE,
                ExtensionMessageKey::EXTENSION_INSTALL_OVERWRITE,
                ['%extension%' => $slug],
                ['install_id' => $installId, 'extension' => $slug, 'was_active' => $wasActive],
            );
        }

        return WorkflowResult::requiresReview([
            'install_id' => $installId,
            'extension' => $slug,
            'name' => $name,
            'version' => $version,
            'was_active' => $wasActive,
            'dependencies' => $dependencyPreflight?->context()['dependencies'] ?? [],
            'deactivate' => $deactivationTargets,
        ], $issues, [
            'install_id' => $installId,
            'extension' => $slug,
            'name' => $name,
            'version' => $version,
            'was_active' => $wasActive,
            'dependencies' => $dependencyPreflight?->context()['dependencies'] ?? [],
            'deactivate' => $deactivationTargets,
            'live_operation_continuation' => [
                'operation' => LiveOperationQueueFactory::EXTENSION_INSTALL_APPLY,
                'label' => sprintf('Install extension %s', $slug),
                'payload' => [
                    'install_id' => $installId,
                    'extension' => $slug,
                    'was_active' => $wasActive,
                    'trigger' => 'admin_ui',
                    'environment' => $this->environment,
                ],
            ],
        ], [
            ...$validation->messages(),
            ...$extensionValidation->messages(),
        ]);
    }
}
