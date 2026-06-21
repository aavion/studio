<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use App\Core\Manifest\ManifestSpec;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final readonly class ExtensionFaultResetter
{
    private ExtensionSpec $validationSpec;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $projectDir,
        private WorkflowResultMessageReporterInterface $messageReporter,
        private string $environment = 'test',
        private ExtensionDiscovery $discovery = new ExtensionDiscovery(),
        private ExtensionValidator $validator = new ExtensionValidator(),
        private PathGuard $pathGuard = new PathGuard(),
        ?ExtensionSpec $validationSpec = null,
        private ExtensionManifestVariables $manifestVariables = new ExtensionManifestVariables(),
    ) {
        $this->validationSpec = $validationSpec ?? ExtensionSpec::create()
            ->withInventoryDepth(PHP_INT_MAX)
            ->withLintingChecks();
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function resetFault(string $extensionName): WorkflowResult
    {
        return $this->report($this->doResetFault($extensionName), ['extension' => $extensionName]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function doResetFault(string $extensionName): WorkflowResult
    {
        $extension = $this->extension($extensionName);

        if (null === $extension) {
            return $this->extensionNotFound($extensionName);
        }

        if (ExtensionStatus::Faulty !== $extension->status()) {
            return $this->statusBlocked($extension);
        }

        if (!$this->isManagedFilesystemExtension($extension)) {
            return $this->statusBlocked($extension, 'not_filesystem_extension');
        }

        $candidateResult = $this->discoverCandidate($extension);

        if (!$candidateResult->isSuccess()) {
            return WorkflowResult::invalid($candidateResult->issues(), [
                'extension' => $extensionName,
                'path' => $extension->path(),
                'discovery_context' => $candidateResult->context(),
            ], $candidateResult->messages());
        }

        $candidate = $candidateResult->value();
        $validation = $this->validator->validate($candidate, $this->validationSpec);

        if (!$validation->isSuccess()) {
            return WorkflowResult::invalid($validation->issues(), [
                'extension' => $extensionName,
                'path' => $extension->path(),
                'validation_context' => $validation->context(),
            ], $validation->messages());
        }

        try {
            $path = $this->relativeExtensionPath($candidate);
            $scopes = ExtensionScope::fromManifestValue((string) $candidate->manifest()->get('EXTENSION_SCOPE', ''));
        } catch (InvalidArgumentException) {
            return WorkflowResult::invalid([
                Message::create(
                    ExtensionMessageCode::EXTENSION_IDENTIFIER_INVALID,
                    ExtensionMessageKey::EXTENSION_IDENTIFIER_INVALID,
                    ['%identifier%' => $extensionName],
                    ['extension' => $extensionName, 'path' => $extension->path()],
                    MessageLevel::Error,
                ),
            ]);
        }

        $extension->syncRegistryState(
            $scopes,
            $path,
            $this->manifestString($candidate, 'EXTENSION_VERSION'),
            $this->metadata($candidate, $extension),
        );
        $this->entityManager->flush();

        $change = [
            'extension' => $extensionName,
            'action' => 'fault_reset',
            'status' => ExtensionStatus::Inactive->value,
        ];

        return WorkflowResult::success([
            'extension' => $extensionName,
            'changes' => [$change],
            'asset_rebuild' => false,
        ], [
            'extension' => $extensionName,
            'path' => $extension->path(),
            'changes' => [$change],
        ], [
            ...$validation->messages(),
            Message::create(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_FAULT_RESET,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_FAULT_RESET,
                ['%extension%' => $extensionName],
                ['extension' => $extensionName, 'path' => $extension->path()],
                MessageLevel::Success,
            ),
        ]);
    }

    private function extension(string $extensionName): ?Extension
    {
        $extension = $this->entityManager->getRepository(Extension::class)->findOneBy([
            'extensionName' => $extensionName,
        ]);

        return $extension instanceof Extension ? $extension : null;
    }

    /**
     * @return WorkflowResult<ExtensionCandidate>
     */
    private function discoverCandidate(Extension $extension): WorkflowResult
    {
        $source = ExtensionSource::single('extension', $extension->path(), $this->extensionManifestSpec());
        $discovery = $this->discovery->discoverSources($this->projectDir, [$source]);

        if (!$discovery->isSuccess()) {
            return WorkflowResult::invalid($discovery->issues(), [
                'extension' => $extension->extensionName(),
                'path' => $extension->path(),
                'discovery_context' => $discovery->context(),
            ], $discovery->messages());
        }

        $candidate = $discovery->value()[0] ?? null;

        if (!$candidate instanceof ExtensionCandidate) {
            $manifestPath = $extension->path().'/.manifest';

            return WorkflowResult::invalid([
                Message::create(
                    ExtensionMessageCode::EXTENSION_REQUIRED_FILE_MISSING,
                    ExtensionMessageKey::EXTENSION_REQUIRED_FILE_MISSING,
                    ['%path%' => $manifestPath],
                    ['extension' => $extension->extensionName(), 'path' => $manifestPath],
                    MessageLevel::Error,
                ),
            ]);
        }

        return WorkflowResult::success($candidate, [
            'extension' => $extension->extensionName(),
            'path' => $extension->path(),
            'environment' => $this->environment,
        ], $discovery->messages());
    }

    private function isManagedFilesystemExtension(Extension $extension): bool
    {
        if (!$this->pathGuard->isRelativePath($extension->path())) {
            return false;
        }

        $path = $this->pathGuard->relativePath($extension->path());

        return 'extensions' !== $path && str_starts_with($path, 'extensions/');
    }

    private function relativeExtensionPath(ExtensionCandidate $candidate): string
    {
        $projectDir = rtrim(str_replace('\\', '/', $this->projectDir), '/');
        $directory = str_replace('\\', '/', $candidate->directory());

        if (!str_starts_with($directory, $projectDir.'/')) {
            throw new InvalidArgumentException('Extension candidate directory must be inside the project directory.');
        }

        return $this->pathGuard->relativePath(substr($directory, strlen($projectDir) + 1));
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(ExtensionCandidate $candidate, Extension $extension): array
    {
        return [
            'registry_state' => 'available',
            'manifest' => $candidate->manifest()->all(),
            'variables' => $this->manifestVariables->fromManifest($extension->extensionName(), $candidate->manifest()),
            'display_name' => $candidate->manifest()->get('EXTENSION_NAME'),
            'description' => $candidate->manifest()->get('EXTENSION_DESCRIPTION'),
            'dependencies' => $candidate->manifest()->get('EXTENSION_DEPENDENCIES'),
            'validation' => [
                'issue_count' => 0,
                'issues' => [],
            ],
            'last_fault' => [
                'recovered_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'metadata' => $this->faultMetadata($extension),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function faultMetadata(Extension $extension): array
    {
        return array_intersect_key($extension->metadata(), array_flip([
            'registry_state',
            'validation',
            'runtime_failure',
            'runtime_loader',
        ]));
    }

    private function manifestString(ExtensionCandidate $candidate, string $key): ?string
    {
        $value = $candidate->manifest()->get($key);

        return is_scalar($value) ? (string) $value : null;
    }

    private function extensionManifestSpec(): ManifestSpec
    {
        return ExtensionManifestSpec::create();
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
                MessageLevel::Warning,
            ),
        ]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function statusBlocked(Extension $extension, string $reason = 'status'): WorkflowResult
    {
        return WorkflowResult::blocked([
            Message::create(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_STATUS_BLOCKED,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_STATUS_BLOCKED,
                ['%extension%' => $extension->extensionName(), '%status%' => $extension->status()->value],
                ['extension' => $extension->extensionName(), 'status' => $extension->status()->value, 'reason' => $reason],
                MessageLevel::Warning,
            ),
        ]);
    }

    private function report(WorkflowResult $result, array $context = []): WorkflowResult
    {
        return $this->messageReporter->report($result, [
            ...$context,
            'operation' => 'extension.fault_reset',
        ]);
    }
}
