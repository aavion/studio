<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Extension\ExtensionPhpLoader;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Operation\ActionQueue;
use App\Core\Operation\OperationMessageKey;
use App\Core\Validation\IdentifierSpec;
use App\Core\Workflow\WorkflowResult;

final readonly class ExtensionOperationLiveOperationProvider implements LiveOperationQueueProviderInterface
{
    public function __construct(
        private ExtensionRuntimeContributionRegistry $runtimeContributions,
        private ?ExtensionPhpLoader $extensionPhpLoader = null,
    ) {
    }

    public function operation(): string
    {
        return LiveOperationQueueFactory::EXTENSION_OPERATION;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<ActionQueue>
     */
    public function create(array $payload = []): WorkflowResult
    {
        $target = $this->stringValue($payload['target'] ?? null);
        $extension = $this->stringValue($payload['extension'] ?? null);

        if (
            null === $target
            || !IdentifierSpec::isMachineIdentifier($target, minLength: 3)
            || (null !== $extension && !str_starts_with($target, $extension.'.'))
        ) {
            return $this->invalid($payload, 'invalid_target');
        }

        $this->extensionPhpLoader?->loadActiveExtensions();
        $definition = $this->runtimeContributions->extensionOperation($target);
        if (null === $definition || (null !== $extension && $definition->extensionName() !== $extension)) {
            return $this->invalid($payload, 'unregistered_target');
        }

        $queue = $this->runtimeContributions->extensionActionQueue($definition->target(), $this->payload($payload));
        if (null === $queue) {
            return $this->invalid($payload, 'missing_action_queue');
        }

        return WorkflowResult::success(ActionQueue::create($queue->name(), $queue->actions(), $queue->stopOnFailure(), [
            ...$queue->context(),
            'operation' => $this->operation(),
            'extension' => $definition->extensionName(),
            'target' => $definition->target(),
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<ActionQueue>
     */
    private function invalid(array $payload, string $reason): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::warning(
                CommonMessageCode::E_INVALID_ARGUMENT,
                OperationMessageKey::OPERATION_INVALID_PAYLOAD,
                ['%operation%' => $this->operation()],
                ['operation' => $this->operation(), 'reason' => $reason, 'payload_keys' => array_keys($payload)],
            ),
        ], ['operation' => $this->operation(), 'reason' => $reason, 'payload_keys' => array_keys($payload)]);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function payload(array $payload): array
    {
        $safe = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key) || in_array($key, ['extension', 'target'], true)) {
                continue;
            }

            $normalized = $this->payloadValue($value, 0);
            if (null !== $normalized) {
                $safe[$key] = $normalized;
            }
        }

        return $safe;
    }

    private function payloadValue(mixed $value, int $depth): mixed
    {
        if ($depth > 4) {
            return null;
        }

        if (null === $value || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return substr($value, 0, 4096);
        }

        if (is_array($value)) {
            $normalized = [];
            foreach (array_slice($value, 0, 100, preserve_keys: true) as $key => $item) {
                if (!is_string($key) && !is_int($key)) {
                    continue;
                }

                $normalizedValue = $this->payloadValue($item, $depth + 1);
                if (null !== $normalizedValue) {
                    $normalized[$key] = $normalizedValue;
                }
            }

            return $normalized;
        }

        return null;
    }
}
