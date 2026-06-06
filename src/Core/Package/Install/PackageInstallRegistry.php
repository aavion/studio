<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Package\ExtensionPackageStatus;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class PackageInstallRegistry
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function package(string $packageName): ?ExtensionPackage
    {
        $package = $this->entityManager->getRepository(ExtensionPackage::class)->findOneBy([
            'packageName' => $packageName,
        ]);

        return $package instanceof ExtensionPackage ? $package : null;
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $packageNames
     *
     * @return array<string, ExtensionPackageStatus>
     */
    public function statusSnapshots(array $packageNames): array
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
    public function restoreStatuses(array $statuses): array
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
}
