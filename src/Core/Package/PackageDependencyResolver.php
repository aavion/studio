<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Message\Message;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use App\View\SystemPackageMetadataProvider;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PackageDependencyResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?SystemPackageMetadataProvider $systemPackageMetadata = null,
        private PackageDependencyParser $dependencyParser = new PackageDependencyParser(),
    ) {
    }

    /**
     * @return WorkflowResult<array{packages: list<ExtensionPackage>, dependencies: list<array<string, mixed>>}>
     */
    public function resolve(ExtensionPackage $package): WorkflowResult
    {
        $packages = [];
        $dependencies = [];
        $issues = [];

        $this->resolvePackage($package, $packages, $dependencies, $issues);

        if ([] !== $issues) {
            return WorkflowResult::blocked($issues, [
                'package' => $package->packageName(),
                'dependencies' => $dependencies,
            ]);
        }

        return WorkflowResult::success([
            'packages' => array_values($packages),
            'dependencies' => $dependencies,
        ], [
            'package' => $package->packageName(),
            'dependencies' => $dependencies,
        ], [
            Message::debug(
                MessageCode::PACKAGE_DEPENDENCY_RESOLVED,
                MessageKey::PACKAGE_DEPENDENCY_RESOLVED,
                ['%package%' => $package->packageName(), '%count%' => count($dependencies)],
                [
                    'package' => $package->packageName(),
                    'dependency_count' => count($dependencies),
                    'dependencies' => $dependencies,
                ],
            ),
        ]);
    }

    /**
     * @param list<string> $excludedPackageNames
     *
     * @return list<ExtensionPackage>
     */
    public function activeDependentsOf(ExtensionPackage $package, array $excludedPackageNames = []): array
    {
        $excluded = array_fill_keys($excludedPackageNames, true);
        $excluded[$package->packageName()] = true;
        $targetPackageName = $package->packageName();
        $dependents = [];

        foreach ($this->entityManager->getRepository(ExtensionPackage::class)->findBy(['status' => ExtensionPackageStatus::Active]) as $candidate) {
            if (
                !$candidate instanceof ExtensionPackage
                || isset($excluded[$candidate->packageName()])
            ) {
                continue;
            }

            $depth = $this->dependencyDepthToPackage($candidate, $targetPackageName);

            if (null === $depth) {
                continue;
            }

            $dependents[$candidate->packageName()] = [
                'package' => $candidate,
                'depth' => $depth,
            ];
        }

        uasort($dependents, static function (array $left, array $right): int {
            $depth = $right['depth'] <=> $left['depth'];

            if (0 !== $depth) {
                return $depth;
            }

            return $left['package']->packageName() <=> $right['package']->packageName();
        });

        return array_values(array_map(
            static fn (array $dependent): ExtensionPackage => $dependent['package'],
            $dependents,
        ));
    }

    /**
     * @param array<string, ExtensionPackage> $packages
     * @param list<array<string, mixed>> $dependencies
     * @param list<Message> $issues
     * @param list<string> $stack
     */
    private function resolvePackage(ExtensionPackage $package, array &$packages, array &$dependencies, array &$issues, array $stack = []): void
    {
        if (in_array($package->packageName(), $stack, true)) {
            $cycleStart = array_search($package->packageName(), $stack, true);
            $cycle = array_slice($stack, false === $cycleStart ? 0 : $cycleStart);
            $cycle[] = $package->packageName();
            $issues[] = Message::create(
                MessageCode::PACKAGE_DEPENDENCY_CYCLE,
                MessageKey::PACKAGE_DEPENDENCY_CYCLE,
                ['%cycle%' => implode(' -> ', $cycle)],
                ['package' => $package->packageName(), 'cycle' => $cycle],
                MessageLevel::Error,
            );

            return;
        }

        if (isset($packages[$package->packageName()])) {
            return;
        }

        $packages[$package->packageName()] = $package;
        $stack[] = $package->packageName();

        foreach ($this->dependencies($package, $issues) as [$dependencyName, $minVersion]) {
            if ('system' === $dependencyName) {
                $this->resolveSystemPackage($package, $minVersion, $dependencies, $issues);

                continue;
            }

            $dependency = $this->package($dependencyName);
            $currentVersion = $dependency?->installedVersion() ?? $dependency?->manifestVersion();
            $status = $dependency?->status();
            $dependencies[] = [
                'package' => $dependencyName,
                'required_min_version' => $minVersion,
                'installed_version' => $currentVersion,
                'status' => $status?->value,
                'required_by' => $package->packageName(),
            ];

            if (null === $dependency) {
                $issues[] = Message::create(
                    MessageCode::PACKAGE_DEPENDENCY_MISSING,
                    MessageKey::PACKAGE_DEPENDENCY_MISSING,
                    ['%package%' => $dependencyName, '%required_by%' => $package->packageName()],
                    ['package' => $dependencyName, 'required_by' => $package->packageName()],
                    MessageLevel::Error,
                );

                continue;
            }

            if (in_array($dependency->status(), [
                ExtensionPackageStatus::Removed,
                ExtensionPackageStatus::Faulty,
            ], true)) {
                $issues[] = Message::create(
                    MessageCode::PACKAGE_DEPENDENCY_STATUS_BLOCKED,
                    MessageKey::PACKAGE_DEPENDENCY_STATUS_BLOCKED,
                    ['%package%' => $dependencyName, '%status%' => $dependency->status()->value],
                    ['package' => $dependencyName, 'status' => $dependency->status()->value, 'required_by' => $package->packageName()],
                    MessageLevel::Error,
                );

                continue;
            }

            if (null === $currentVersion || version_compare($currentVersion, $minVersion, '<')) {
                $issues[] = Message::create(
                    MessageCode::PACKAGE_DEPENDENCY_VERSION_UNSATISFIED,
                    MessageKey::PACKAGE_DEPENDENCY_VERSION_UNSATISFIED,
                    ['%package%' => $dependencyName, '%required_version%' => $minVersion, '%installed_version%' => $currentVersion ?? ''],
                    ['package' => $dependencyName, 'required_version' => $minVersion, 'installed_version' => $currentVersion, 'required_by' => $package->packageName()],
                    MessageLevel::Error,
                );

                continue;
            }

            $this->resolvePackage($dependency, $packages, $dependencies, $issues, $stack);
        }
    }

    /**
     * @param list<array<string, mixed>> $dependencies
     * @param list<Message> $issues
     */
    private function resolveSystemPackage(
        ExtensionPackage $package,
        string $minVersion,
        array &$dependencies,
        array &$issues,
    ): void {
        $metadata = $this->systemPackageMetadata?->metadata();
        $currentVersion = is_array($metadata) && isset($metadata['version']) && is_string($metadata['version'])
            ? $metadata['version']
            : null;

        $dependencies[] = [
            'package' => 'system',
            'required_min_version' => $minVersion,
            'installed_version' => $currentVersion,
            'status' => ExtensionPackageStatus::Active->value,
            'required_by' => $package->packageName(),
        ];

        if (null === $currentVersion || version_compare($currentVersion, $minVersion, '<')) {
            $issues[] = Message::create(
                MessageCode::PACKAGE_DEPENDENCY_VERSION_UNSATISFIED,
                MessageKey::PACKAGE_DEPENDENCY_VERSION_UNSATISFIED,
                ['%package%' => 'system', '%required_version%' => $minVersion, '%installed_version%' => $currentVersion ?? ''],
                ['package' => 'system', 'required_version' => $minVersion, 'installed_version' => $currentVersion, 'required_by' => $package->packageName()],
                MessageLevel::Error,
            );
        }
    }

    private function package(string $packageName): ?ExtensionPackage
    {
        $package = $this->entityManager->getRepository(ExtensionPackage::class)->findOneBy([
            'packageName' => $packageName,
        ]);

        return $package instanceof ExtensionPackage ? $package : null;
    }

    /**
     * @param array<string, true> $seen
     */
    private function dependencyDepthToPackage(ExtensionPackage $package, string $targetPackageName, array $seen = []): ?int
    {
        if (isset($seen[$package->packageName()])) {
            return null;
        }

        $seen[$package->packageName()] = true;
        $deepest = null;

        foreach ($this->dependencies($package) as [$dependencyName]) {
            if ($dependencyName === $targetPackageName) {
                $deepest = max($deepest ?? 0, 1);

                continue;
            }

            if (isset($seen[$dependencyName])) {
                continue;
            }

            $dependency = $this->package($dependencyName);

            if (!$dependency instanceof ExtensionPackage) {
                continue;
            }

            $depth = $this->dependencyDepthToPackage($dependency, $targetPackageName, $seen);

            if (null !== $depth) {
                $deepest = max($deepest ?? 0, $depth + 1);
            }
        }

        return $deepest;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function dependencies(ExtensionPackage $package, ?array &$issues = null): array
    {
        $metadata = $package->metadata();
        $manifest = $metadata['manifest'] ?? [];
        $value = is_array($manifest)
            ? ($manifest['PACKAGE_DEPENDENCIES'] ?? null)
            : ($metadata['dependencies'] ?? null);

        $dependencies = $this->dependencyParser->parse($value);

        if (null !== $dependencies) {
            return $dependencies;
        }

        if (null !== $issues) {
            $issues[] = Message::create(
                MessageCode::PACKAGE_DEPENDENCY_INVALID,
                MessageKey::PACKAGE_DEPENDENCY_INVALID,
                ['%package%' => $package->packageName()],
                ['package' => $package->packageName(), 'value' => $value],
                MessageLevel::Error,
            );
        }

        return [];
    }
}
