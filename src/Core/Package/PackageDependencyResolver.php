<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Message\Message;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PackageDependencyResolver
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
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
     * @param array<string, ExtensionPackage> $packages
     * @param list<array<string, mixed>> $dependencies
     * @param list<Message> $issues
     */
    private function resolvePackage(ExtensionPackage $package, array &$packages, array &$dependencies, array &$issues): void
    {
        if (isset($packages[$package->packageName()])) {
            return;
        }

        $packages[$package->packageName()] = $package;

        foreach ($this->dependencies($package) as [$dependencyName, $minVersion]) {
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

            $this->resolvePackage($dependency, $packages, $dependencies, $issues);
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
     * @return list<array{0: string, 1: string}>
     */
    private function dependencies(ExtensionPackage $package): array
    {
        $metadata = $package->metadata();
        $manifest = $metadata['manifest'] ?? [];
        $value = is_array($manifest)
            ? ($manifest['PACKAGE_DEPENDENCIES'] ?? null)
            : ($metadata['dependencies'] ?? null);

        if (!is_string($value) || '' === trim($value) || '[]' === trim($value)) {
            return [];
        }

        preg_match_all('/\[\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]/', $value, $matches, PREG_SET_ORDER);

        return array_map(
            static fn (array $match): array => [$match[1], $match[2]],
            $matches,
        );
    }
}
