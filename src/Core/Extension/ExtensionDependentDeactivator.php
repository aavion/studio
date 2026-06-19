<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Entity\Extension;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ExtensionDependentDeactivator
{
    private ExtensionDependencyResolver $dependencyResolver;

    public function __construct(
        EntityManagerInterface $entityManager,
        ?ExtensionDependencyResolver $dependencyResolver = null,
    ) {
        $this->dependencyResolver = $dependencyResolver ?? new ExtensionDependencyResolver($entityManager);
    }

    /**
     * @return array{changes: list<array{extension: string, action: string, status: string, dependency: string, reason: string}>, messages: list<Message>}
     */
    public function deactivateActiveDependents(Extension $extension, string $reason): array
    {
        $changes = [];
        $messages = [];

        foreach ($this->dependencyResolver->activeDependentsOf($extension) as $dependent) {
            if (ExtensionStatus::Active !== $dependent->status() || !$dependent->deactivate()) {
                continue;
            }

            $changes[] = [
                'extension' => $dependent->extensionName(),
                'action' => 'deactivated',
                'status' => $dependent->status()->value,
                'dependency' => $extension->extensionName(),
                'reason' => $reason,
            ];
            $messages[] = Message::create(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_DEPENDENT_DEACTIVATED,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_DEPENDENT_DEACTIVATED,
                ['%extension%' => $dependent->extensionName(), '%dependency%' => $extension->extensionName()],
                ['extension' => $dependent->extensionName(), 'dependency' => $extension->extensionName(), 'reason' => $reason],
                MessageLevel::Warning,
            );
        }

        return ['changes' => $changes, 'messages' => $messages];
    }
}
