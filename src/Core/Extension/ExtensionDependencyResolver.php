<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;
use App\View\SystemExtensionMetadataProvider;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ExtensionDependencyResolver
{
    private ExtensionLifecycleStore $store;

    public function __construct(
        EntityManagerInterface $entityManager,
        private ?SystemExtensionMetadataProvider $systemExtensionMetadata = null,
        private ExtensionDependencyMetadataReader $dependencyReader = new ExtensionDependencyMetadataReader(),
        ?ExtensionLifecycleStore $store = null,
    ) {
        $this->store = $store ?? new ExtensionLifecycleStore($entityManager);
    }

    /**
     * @return WorkflowResult<array{extensions: list<Extension>, dependencies: list<array<string, mixed>>}>
     */
    public function resolve(Extension $extension): WorkflowResult
    {
        $extensions = [];
        $dependencies = [];
        $issues = [];

        $this->resolveExtension($extension, $extensions, $dependencies, $issues);

        if ([] !== $issues) {
            return WorkflowResult::blocked($issues, [
                'extension' => $extension->extensionName(),
                'dependencies' => $dependencies,
            ]);
        }

        return WorkflowResult::success([
            'extensions' => array_values($extensions),
            'dependencies' => $dependencies,
        ], [
            'extension' => $extension->extensionName(),
            'dependencies' => $dependencies,
        ], [
            Message::debug(
                ExtensionMessageCode::EXTENSION_DEPENDENCY_RESOLVED,
                ExtensionMessageKey::EXTENSION_DEPENDENCY_RESOLVED,
                ['%extension%' => $extension->extensionName(), '%count%' => count($dependencies)],
                [
                    'extension' => $extension->extensionName(),
                    'dependency_count' => count($dependencies),
                    'dependencies' => $dependencies,
                ],
            ),
        ]);
    }

    /**
     * @param list<string> $excludedExtensionNames
     *
     * @return list<Extension>
     */
    public function activeDependentsOf(Extension $extension, array $excludedExtensionNames = []): array
    {
        $excluded = array_fill_keys($excludedExtensionNames, true);
        $excluded[$extension->extensionName()] = true;
        $targetExtensionName = $extension->extensionName();
        $dependents = [];

        foreach ($this->store->activeExtensions() as $candidate) {
            if (
                isset($excluded[$candidate->extensionName()])
            ) {
                continue;
            }

            $depth = $this->dependencyDepthToExtension($candidate, $targetExtensionName);

            if (null === $depth) {
                continue;
            }

            $dependents[$candidate->extensionName()] = [
                'extension' => $candidate,
                'depth' => $depth,
            ];
        }

        uasort($dependents, static function (array $left, array $right): int {
            $depth = $right['depth'] <=> $left['depth'];

            if (0 !== $depth) {
                return $depth;
            }

            return $left['extension']->extensionName() <=> $right['extension']->extensionName();
        });

        return array_values(array_map(
            static fn (array $dependent): Extension => $dependent['extension'],
            $dependents,
        ));
    }

    /**
     * @param array<string, Extension> $extensions
     * @param list<array<string, mixed>> $dependencies
     * @param list<Message> $issues
     * @param list<string> $stack
     */
    private function resolveExtension(Extension $extension, array &$extensions, array &$dependencies, array &$issues, array $stack = []): void
    {
        if (in_array($extension->extensionName(), $stack, true)) {
            $cycleStart = array_search($extension->extensionName(), $stack, true);
            $cycle = array_slice($stack, false === $cycleStart ? 0 : $cycleStart);
            $cycle[] = $extension->extensionName();
            $issues[] = Message::create(
                ExtensionMessageCode::EXTENSION_DEPENDENCY_CYCLE,
                ExtensionMessageKey::EXTENSION_DEPENDENCY_CYCLE,
                ['%cycle%' => implode(' -> ', $cycle)],
                ['extension' => $extension->extensionName(), 'cycle' => $cycle],
                MessageLevel::Error,
            );

            return;
        }

        if (isset($extensions[$extension->extensionName()])) {
            return;
        }

        $extensions[$extension->extensionName()] = $extension;
        $stack[] = $extension->extensionName();

        foreach ($this->dependencyReader->dependencies($extension, $issues) as [$dependencyName, $minVersion]) {
            if ('system' === $dependencyName) {
                $this->resolveSystemExtension($extension, $minVersion, $dependencies, $issues);

                continue;
            }

            $dependency = $this->store->extension($dependencyName);
            $currentVersion = $dependency?->installedVersion() ?? $dependency?->manifestVersion();
            $status = $dependency?->status();
            $dependencies[] = [
                'extension' => $dependencyName,
                'required_min_version' => $minVersion,
                'installed_version' => $currentVersion,
                'status' => $status?->value,
                'required_by' => $extension->extensionName(),
            ];

            if (null === $dependency) {
                $issues[] = Message::create(
                    ExtensionMessageCode::EXTENSION_DEPENDENCY_MISSING,
                    ExtensionMessageKey::EXTENSION_DEPENDENCY_MISSING,
                    ['%extension%' => $dependencyName, '%required_by%' => $extension->extensionName()],
                    ['extension' => $dependencyName, 'required_by' => $extension->extensionName()],
                    MessageLevel::Error,
                );

                continue;
            }

            if (in_array($dependency->status(), [
                ExtensionStatus::Removed,
                ExtensionStatus::Faulty,
            ], true)) {
                $issues[] = Message::create(
                    ExtensionMessageCode::EXTENSION_DEPENDENCY_STATUS_BLOCKED,
                    ExtensionMessageKey::EXTENSION_DEPENDENCY_STATUS_BLOCKED,
                    ['%extension%' => $dependencyName, '%status%' => $dependency->status()->value],
                    ['extension' => $dependencyName, 'status' => $dependency->status()->value, 'required_by' => $extension->extensionName()],
                    MessageLevel::Error,
                );

                continue;
            }

            if (null === $currentVersion || version_compare($currentVersion, $minVersion, '<')) {
                $issues[] = Message::create(
                    ExtensionMessageCode::EXTENSION_DEPENDENCY_VERSION_UNSATISFIED,
                    ExtensionMessageKey::EXTENSION_DEPENDENCY_VERSION_UNSATISFIED,
                    ['%extension%' => $dependencyName, '%required_version%' => $minVersion, '%installed_version%' => $currentVersion ?? ''],
                    ['extension' => $dependencyName, 'required_version' => $minVersion, 'installed_version' => $currentVersion, 'required_by' => $extension->extensionName()],
                    MessageLevel::Error,
                );

                continue;
            }

            $this->resolveExtension($dependency, $extensions, $dependencies, $issues, $stack);
        }
    }

    /**
     * @param list<array<string, mixed>> $dependencies
     * @param list<Message> $issues
     */
    private function resolveSystemExtension(
        Extension $extension,
        string $minVersion,
        array &$dependencies,
        array &$issues,
    ): void {
        $metadata = $this->systemExtensionMetadata?->metadata();
        $currentVersion = is_array($metadata) && isset($metadata['version']) && is_string($metadata['version'])
            ? $metadata['version']
            : null;

        $dependencies[] = [
            'extension' => 'system',
            'required_min_version' => $minVersion,
            'installed_version' => $currentVersion,
            'status' => ExtensionStatus::Active->value,
            'required_by' => $extension->extensionName(),
        ];

        if (null === $currentVersion || version_compare($currentVersion, $minVersion, '<')) {
            $issues[] = Message::create(
                ExtensionMessageCode::EXTENSION_DEPENDENCY_VERSION_UNSATISFIED,
                ExtensionMessageKey::EXTENSION_DEPENDENCY_VERSION_UNSATISFIED,
                ['%extension%' => 'system', '%required_version%' => $minVersion, '%installed_version%' => $currentVersion ?? ''],
                ['extension' => 'system', 'required_version' => $minVersion, 'installed_version' => $currentVersion, 'required_by' => $extension->extensionName()],
                MessageLevel::Error,
            );
        }
    }

    /**
     * @param array<string, true> $seen
     */
    private function dependencyDepthToExtension(Extension $extension, string $targetExtensionName, array $seen = []): ?int
    {
        if (isset($seen[$extension->extensionName()])) {
            return null;
        }

        $seen[$extension->extensionName()] = true;
        $deepest = null;

        foreach ($this->dependencyReader->dependencies($extension) as [$dependencyName]) {
            if ($dependencyName === $targetExtensionName) {
                $deepest = max($deepest ?? 0, 1);

                continue;
            }

            if (isset($seen[$dependencyName])) {
                continue;
            }

            $dependency = $this->store->extension($dependencyName);

            if (!$dependency instanceof Extension) {
                continue;
            }

            $depth = $this->dependencyDepthToExtension($dependency, $targetExtensionName, $seen);

            if (null !== $depth) {
                $deepest = max($deepest ?? 0, $depth + 1);
            }
        }

        return $deepest;
    }

}
