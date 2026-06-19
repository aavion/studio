<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use App\Core\Id\UuidFactory;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\Content\ExtensionContentSchemaImpact;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final readonly class ExtensionRegistryHandler
{
    private ExtensionSpec $validationSpec;
    private ExtensionDependencyResolver $dependencyResolver;
    private ExtensionLifecycleStore $store;
    private ExtensionRegistrySyncFinalizer $syncFinalizer;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $projectDir,
        private ExtensionValidator $validator = new ExtensionValidator(),
        private PathGuard $pathGuard = new PathGuard(),
        ?ExtensionLifecycleAssetRebuilderInterface $assetRebuilder = null,
        string $environment = 'test',
        private UuidFactory $uuidFactory = new UuidFactory(),
        ?ExtensionSpec $validationSpec = null,
        ?ExtensionDependencyResolver $dependencyResolver = null,
        ?ExtensionLifecycleStore $store = null,
        ?ExtensionRegistrySyncFinalizer $syncFinalizer = null,
        private ?ExtensionContentSchemaImpact $contentSchemaImpact = null,
        private ExtensionManifestVariables $manifestVariables = new ExtensionManifestVariables(),
    ) {
        $this->validationSpec = $validationSpec ?? ExtensionSpec::create()
            ->withInventoryDepth(4)
            ->withLintingChecks();
        $this->dependencyResolver = $dependencyResolver ?? new ExtensionDependencyResolver($entityManager);
        $this->store = $store ?? new ExtensionLifecycleStore($entityManager);
        $this->syncFinalizer = $syncFinalizer ?? new ExtensionRegistrySyncFinalizer(
            $entityManager,
            $assetRebuilder,
            $environment,
        );
    }

    /**
     * @param iterable<ExtensionCandidate> $candidates
     *
     * @return WorkflowResult<list<array{extension: string, action: string, status: string}>>
     */
    public function synchronize(iterable $candidates): WorkflowResult
    {
        $extensions = $this->store->indexedExtensions();
        $seen = [];
        $changes = [];
        $messages = [];
        $issues = [];
        $assetRebuildTriggers = [];

        foreach ($candidates as $candidate) {
            if ('extension' !== $candidate->source()->name()) {
                continue;
            }

            try {
                $extensionName = $this->extensionName($candidate);
                $path = $this->relativeExtensionPath($candidate);
                $scopes = ExtensionScope::fromManifestValue((string) $candidate->manifest()->get('EXTENSION_SCOPE', ''));
            } catch (InvalidArgumentException) {
                $issues[] = Message::create(
                    ExtensionMessageCode::EXTENSION_IDENTIFIER_INVALID,
                    ExtensionMessageKey::EXTENSION_IDENTIFIER_INVALID,
                    ['%identifier%' => basename($candidate->directory())],
                    ['path' => $candidate->directory(), 'source' => $candidate->source()->name()],
                    MessageLevel::Error,
                );

                continue;
            }

            $seen[$extensionName] = true;
            $isNew = !isset($extensions[$extensionName]);
            $manifestVersion = $candidate->manifest()->get('EXTENSION_VERSION');
            $extension = $extensions[$extensionName] ?? new Extension(
                $this->uuidFactory->generate(),
                $scopes,
                $extensionName,
                $path,
                manifestVersion: $manifestVersion,
                installedVersion: $manifestVersion,
            );

            if ($isNew) {
                $this->entityManager->persist($extension);
                $extensions[$extensionName] = $extension;
            }

            if (!$isNew && $this->keepsExistingFaultyState($extension, $path, $manifestVersion)) {
                continue;
            }

            $validation = $this->validator->validate($candidate, $this->validationSpec);

            if (!$validation->isSuccess()) {
                $wasActive = ExtensionStatus::Active === $extension->status();
                $changed = $extension->markFaulty($path, $manifestVersion, $this->metadata($candidate, 'faulty', $validation->issues()));

                if ($changed || $isNew) {
                    $changes[] = $this->change($extensionName, 'faulty', ExtensionStatus::Faulty);
                    $messages[] = Message::error(
                        ExtensionMessageCode::EXTENSION_REGISTRY_EXTENSION_FAULTY,
                        ExtensionMessageKey::EXTENSION_REGISTRY_EXTENSION_FAULTY,
                        ['%extension%' => $extensionName],
                        ['extension' => $extensionName, 'path' => $path, 'issue_count' => count($validation->issues())],
                    );
                }

                if ($changed && $wasActive) {
                    array_push($messages, ...$this->archiveContentForDeactivatedExtensions([$extension]));
                    $assetRebuildTriggers[] = $this->assetRebuildTrigger($extensionName, 'extension_registry_faulty');
                    $dependentChanges = $this->deactivateActiveDependents($extension, 'extension_registry_faulty');
                    $changes = [...$changes, ...$dependentChanges['changes']];
                    $messages = [...$messages, ...$dependentChanges['messages']];
                    $assetRebuildTriggers = [...$assetRebuildTriggers, ...$dependentChanges['asset_rebuild_triggers']];
                }

                continue;
            }

            $changed = $extension->syncRegistryState($scopes, $path, $manifestVersion, $this->metadata($candidate, 'available'));
            $action = $isNew ? 'registered' : ($changed ? 'updated' : 'unchanged');

            if ('unchanged' !== $action) {
                $changes[] = $this->change($extensionName, $action, $extension->status());
                $messages[] = Message::create(
                    'registered' === $action ? ExtensionMessageCode::EXTENSION_REGISTRY_EXTENSION_REGISTERED : ExtensionMessageCode::EXTENSION_REGISTRY_EXTENSION_UPDATED,
                    'registered' === $action ? ExtensionMessageKey::EXTENSION_REGISTRY_EXTENSION_REGISTERED : ExtensionMessageKey::EXTENSION_REGISTRY_EXTENSION_UPDATED,
                    ['%extension%' => $extensionName],
                    ['extension' => $extensionName, 'path' => $path, 'status' => $extension->status()->value],
                    MessageLevel::Success,
                );
            }

            if ($changed && ExtensionStatus::Active === $extension->status()) {
                $assetRebuildTriggers[] = $this->assetRebuildTrigger($extensionName, 'extension_registry_active_updated');
            }
        }
        foreach ($extensions as $extensionName => $extension) {
            if (isset($seen[$extensionName]) || !$this->store->isManagedFilesystemExtension($extension)) {
                continue;
            }

            $wasActive = ExtensionStatus::Active === $extension->status();

            if ($extension->markRemoved($this->removedMetadata($extension))) {
                $changes[] = $this->change($extensionName, 'removed', ExtensionStatus::Removed);
                $messages[] = Message::error(
                    ExtensionMessageCode::EXTENSION_REGISTRY_EXTENSION_REMOVED,
                    ExtensionMessageKey::EXTENSION_REGISTRY_EXTENSION_REMOVED,
                    ['%extension%' => $extensionName],
                    ['extension' => $extensionName, 'path' => $extension->path()],
                );

                if ($wasActive) {
                    array_push($messages, ...$this->archiveContentForDeactivatedExtensions([$extension]));
                    $assetRebuildTriggers[] = $this->assetRebuildTrigger($extensionName, 'extension_registry_removed');
                    $dependentChanges = $this->deactivateActiveDependents($extension, 'extension_registry_removed');
                    $changes = [...$changes, ...$dependentChanges['changes']];
                    $messages = [...$messages, ...$dependentChanges['messages']];
                    $assetRebuildTriggers = [...$assetRebuildTriggers, ...$dependentChanges['asset_rebuild_triggers']];
                }
            }
        }

        if ([] !== $issues) {
            return WorkflowResult::invalid($issues, ['changes' => $changes], $messages);
        }

        return $this->syncFinalizer->finalize($changes, $messages, $assetRebuildTriggers);
    }

    /**
     * @return array{
     *     changes: list<array{extension: string, action: string, status: string}>,
     *     messages: list<Message>,
     *     asset_rebuild_triggers: list<string>
     * }
     */
    private function deactivateActiveDependents(Extension $extension, string $trigger): array
    {
        $changes = [];
        $messages = [];
        $assetRebuildTriggers = [];

        foreach ($this->dependencyResolver->activeDependentsOf($extension) as $dependent) {
            if (!$dependent->deactivate()) {
                continue;
            }

            $changes[] = $this->change($dependent->extensionName(), 'deactivated', ExtensionStatus::Inactive);
            $messages[] = Message::create(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_DEACTIVATED,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_DEACTIVATED,
                ['%extension%' => $dependent->extensionName()],
                ['extension' => $dependent->extensionName(), 'required_extension' => $extension->extensionName()],
                MessageLevel::Success,
            );
            array_push($messages, ...$this->archiveContentForDeactivatedExtensions([$dependent]));
            $assetRebuildTriggers[] = $this->assetRebuildTrigger($dependent->extensionName(), $trigger);
        }

        return [
            'changes' => $changes,
            'messages' => $messages,
            'asset_rebuild_triggers' => $assetRebuildTriggers,
        ];
    }

    private function extensionName(ExtensionCandidate $candidate): string
    {
        $slug = trim((string) $candidate->manifest()->get('EXTENSION_SLUG', ''));

        if (!ExtensionManifestSpec::isValidSlug($slug)) {
            throw new InvalidArgumentException(sprintf('Extension slug "%s" is invalid.', $slug));
        }

        return $this->pathGuard->relativePath($slug);
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
     * @param list<Message> $issues
     *
     * @return array<string, mixed>
     */
    private function metadata(ExtensionCandidate $candidate, string $state, array $issues = []): array
    {
        $extensionName = $this->extensionName($candidate);

        return [
            'registry_state' => $state,
            'manifest' => $candidate->manifest()->all(),
            'variables' => $this->manifestVariables->fromManifest($extensionName, $candidate->manifest()),
            'slug' => $candidate->manifest()->get('EXTENSION_SLUG'),
            'display_name' => $candidate->manifest()->get('EXTENSION_NAME'),
            'author' => $candidate->manifest()->get('EXTENSION_AUTHOR'),
            'description' => $candidate->manifest()->get('EXTENSION_DESCRIPTION'),
            'dependencies' => $candidate->manifest()->get('EXTENSION_DEPENDENCIES'),
            'license' => $candidate->manifest()->get('EXTENSION_LICENSE'),
            'homepage' => $candidate->manifest()->get('EXTENSION_HOMEPAGE'),
            'source' => $candidate->manifest()->get('EXTENSION_SOURCE'),
            'channel' => $candidate->manifest()->get('EXTENSION_CHANNEL'),
            'image' => $candidate->manifest()->get('EXTENSION_IMAGE'),
            'validation' => [
                'issue_count' => count($issues),
                'issues' => array_map(static fn (Message $issue): array => $issue->toArray(), $issues),
            ],
        ];
    }

    private function removedMetadata(Extension $extension): array
    {
        return [
            ...$extension->metadata(),
            'registry_state' => 'removed',
            'removed_path' => $extension->path(),
        ];
    }

    private function keepsExistingFaultyState(Extension $extension, string $path, ?string $manifestVersion): bool
    {
        return ExtensionStatus::Faulty === $extension->status()
            && $extension->path() === $path
            && $extension->manifestVersion() === $manifestVersion;
    }

    private function change(string $extensionName, string $action, ExtensionStatus $status): array
    {
        return [
            'extension' => $extensionName,
            'action' => $action,
            'status' => $status->value,
        ];
    }

    private function assetRebuildTrigger(string $extensionName, string $trigger): string
    {
        return $trigger.':'.$extensionName;
    }

    /**
     * @param list<Extension> $extensions
     *
     * @return list<Message>
     */
    private function archiveContentForDeactivatedExtensions(array $extensions): array
    {
        if (null === $this->contentSchemaImpact || [] === $extensions) {
            return [];
        }

        return $this->contentSchemaImpact->archivePublicContentForExtensions($extensions)->messages();
    }
}
