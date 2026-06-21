<?php

declare(strict_types=1);

namespace App\Core\Extension\Contribution;

use App\Core\Extension\ExtensionActionQueueProviderInterface;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Extension\ExtensionOperationDefinition;
use App\Core\Extension\ExtensionOperationRegistration;
use App\Core\Message\MessageException;
use App\Core\Operation\ActionQueue;
use App\Entity\Extension;
use App\Scheduler\SchedulerActionQueueProviderInterface;

final class ExtensionRuntimeOperationContributions implements SchedulerActionQueueProviderInterface
{
    /**
     * @var list<ExtensionOperationRegistration>
     */
    private array $operations = [];

    /**
     * @var list<array{extension: string, provider: ExtensionActionQueueProviderInterface}>
     */
    private array $actionQueueProviders = [];

    public function addOperation(Extension $extension, ExtensionOperationDefinition $definition, ExtensionRuntimeContributionGuard $guard): void
    {
        $guard->assertOperationDefinition($extension, $definition);
        foreach ($this->operations as $existing) {
            if ($existing->identifier() === $definition->identifier() || $existing->target() === $definition->target()) {
                throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
                    '%extension%' => $extension->extensionName(),
                    '%type%' => ExtensionOperationDefinition::class.'('.$definition->identifier().') duplicate_identifier_or_target',
                ], [
                    'extension' => $extension->extensionName(),
                    'identifier' => $definition->identifier(),
                    'target' => $definition->target(),
                ]);
            }
        }

        $this->operations[] = new ExtensionOperationRegistration($extension, $definition);
    }

    public function addActionQueueProvider(Extension $extension, ExtensionActionQueueProviderInterface $provider): void
    {
        $this->actionQueueProviders[] = ['extension' => $extension->extensionName(), 'provider' => $provider];
    }

    /**
     * @return list<ExtensionOperationRegistration>
     */
    public function operations(?string $extensionName = null): array
    {
        if (null === $extensionName) {
            return $this->operations;
        }

        return array_values(array_filter(
            $this->operations,
            static fn (ExtensionOperationRegistration $registration): bool => $registration->extensionName() === $extensionName,
        ));
    }

    public function operation(string $target): ?ExtensionOperationRegistration
    {
        foreach ($this->operations as $operation) {
            if ($operation->target() === $target || $operation->identifier() === $target) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function extensionActionQueue(string $target, array $payload = []): ?ActionQueue
    {
        foreach ($this->actionQueueProviders as $entry) {
            if (!str_starts_with($target, $entry['extension'].'.')) {
                continue;
            }

            $queue = $entry['provider']->extensionActionQueue($target, $payload);
            if (null !== $queue) {
                return $queue;
            }
        }

        return null;
    }

    public function schedulerActionQueue(string $target): ?ActionQueue
    {
        return null !== $this->operation($target) ? $this->extensionActionQueue($target) : null;
    }
}
