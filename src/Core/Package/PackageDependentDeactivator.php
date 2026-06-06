<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PackageDependentDeactivator
{
    private PackageDependencyResolver $dependencyResolver;

    public function __construct(
        EntityManagerInterface $entityManager,
        ?PackageDependencyResolver $dependencyResolver = null,
    ) {
        $this->dependencyResolver = $dependencyResolver ?? new PackageDependencyResolver($entityManager);
    }

    /**
     * @return array{changes: list<array{package: string, action: string, status: string, dependency: string, reason: string}>, messages: list<Message>}
     */
    public function deactivateActiveDependents(ExtensionPackage $package, string $reason): array
    {
        $changes = [];
        $messages = [];

        foreach ($this->dependencyResolver->activeDependentsOf($package) as $dependent) {
            if (ExtensionPackageStatus::Active !== $dependent->status() || !$dependent->deactivate()) {
                continue;
            }

            $changes[] = [
                'package' => $dependent->packageName(),
                'action' => 'deactivated',
                'status' => $dependent->status()->value,
                'dependency' => $package->packageName(),
                'reason' => $reason,
            ];
            $messages[] = Message::create(
                PackageMessageCode::PACKAGE_LIFECYCLE_DEPENDENT_DEACTIVATED,
                PackageMessageKey::PACKAGE_LIFECYCLE_DEPENDENT_DEACTIVATED,
                ['%package%' => $dependent->packageName(), '%dependency%' => $package->packageName()],
                ['package' => $dependent->packageName(), 'dependency' => $package->packageName(), 'reason' => $reason],
                MessageLevel::Warning,
            );
        }

        return ['changes' => $changes, 'messages' => $messages];
    }
}
