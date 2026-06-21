<?php

declare(strict_types=1);

namespace App\Core\Extension\Install;

use App\Content\ContentStatus;
use App\Core\Message\Message;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;
use App\Core\Extension\ExtensionStatus;
use App\Entity\ContentItem;
use App\Entity\Extension;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class ExtensionInstallRegistry
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function extension(string $extensionName): ?Extension
    {
        $extension = $this->entityManager->getRepository(Extension::class)->findOneBy([
            'extensionName' => $extensionName,
        ]);

        return $extension instanceof Extension ? $extension : null;
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $extensionNames
     *
     * @return array<string, ExtensionStatus>
     */
    public function statusSnapshots(array $extensionNames): array
    {
        $snapshots = [];

        foreach (array_unique($extensionNames) as $extensionName) {
            $extension = $this->extension($extensionName);

            if ($extension instanceof Extension) {
                $snapshots[$extensionName] = $extension->status();
            }
        }

        return $snapshots;
    }

    /**
     * @param array<string, ExtensionStatus> $statuses
     *
     * @return list<Message>
     */
    public function restoreStatuses(array $statuses): array
    {
        try {
            foreach ($statuses as $extensionName => $status) {
                $extension = $this->extension($extensionName);

                if ($extension instanceof Extension) {
                    $extension->restoreStatus($status);
                }
            }

            $this->entityManager->flush();
        } catch (Throwable $error) {
            return [
                Message::exception(
                    OperationMessageCode::OPERATION_EXCEPTION,
                    OperationMessageKey::OPERATION_EXCEPTION,
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
     * @param array<string, ContentStatus> $snapshots
     *
     * @return list<Message>
     */
    public function restoreContentStatuses(array $snapshots): array
    {
        try {
            foreach ($snapshots as $contentUid => $status) {
                $content = $this->entityManager->find(ContentItem::class, $contentUid);

                if ($content instanceof ContentItem) {
                    $content->restoreStatus($status);
                }
            }

            $this->entityManager->flush();
        } catch (Throwable $error) {
            return [
                Message::exception(
                    OperationMessageCode::OPERATION_EXCEPTION,
                    OperationMessageKey::OPERATION_EXCEPTION,
                    context: [
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                        'rollback' => true,
                        'content_status_restore' => true,
                    ],
                ),
            ];
        }

        return [];
    }
}
