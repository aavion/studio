<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;
use Throwable;

final readonly class PackageDiscoveryRunner
{
    public function __construct(
        private PackageDiscovery $discovery,
        private PackageRegistryHandler $registryHandler,
        private string $projectDir,
        private string $environment,
    ) {
    }

    /**
     * @return OperationResult<array{candidate_count: int, change_count: int, changes: list<array{package: string, action: string, status: string}>}>
     */
    public function __invoke(string $trigger = 'manual'): OperationResult
    {
        $trigger = '' === trim($trigger) ? 'manual' : trim($trigger);
        $baseContext = [
            'trigger' => $trigger,
            'environment' => $this->environment,
        ];

        try {
            $discoveryResult = $this->discovery->discover($this->projectDir, $this->environment);
        } catch (Throwable $error) {
            return $this->exceptionResult($error, $baseContext);
        }

        $candidates = $this->candidatesFrom($discoveryResult);
        $messages = $discoveryResult->messages();
        $context = [
            ...$baseContext,
            'candidate_count' => count($candidates),
        ];

        if (!$discoveryResult->isSuccess()) {
            return OperationResult::invalid($discoveryResult->issues(), $context, $messages);
        }

        try {
            $registryResult = $this->registryHandler->synchronize($candidates);
        } catch (Throwable $error) {
            return $this->exceptionResult($error, $context, $messages);
        }

        $messages = [
            ...$messages,
            ...$registryResult->messages(),
        ];

        if (!$registryResult->isSuccess()) {
            return OperationResult::invalid($registryResult->issues(), [
                ...$context,
                'change_count' => count($registryResult->value() ?? []),
                'changes' => $registryResult->value() ?? [],
            ], $messages);
        }

        $changes = $registryResult->value() ?? [];

        return OperationResult::success([
            'candidate_count' => count($candidates),
            'change_count' => count($changes),
            'changes' => $changes,
        ], [
            ...$context,
            'change_count' => count($changes),
            'changes' => $changes,
        ], $messages);
    }

    /**
     * @return list<PackageCandidate>
     */
    private function candidatesFrom(OperationResult $result): array
    {
        $value = $result->value();

        if (is_array($value)) {
            return array_values(array_filter(
                $value,
                static fn (mixed $candidate): bool => $candidate instanceof PackageCandidate,
            ));
        }

        $candidates = $result->context()['candidates'] ?? [];

        if (!is_array($candidates)) {
            return [];
        }

        return array_values(array_filter(
            $candidates,
            static fn (mixed $candidate): bool => $candidate instanceof PackageCandidate,
        ));
    }

    /**
     * @param array<string, mixed> $context
     * @param list<\App\Core\Message\Message> $messages
     *
     * @return OperationResult<null>
     */
    private function exceptionResult(Throwable $error, array $context, array $messages = []): OperationResult
    {
        return OperationResult::failed([
            OperationIssue::create(
                MessageCode::OPERATION_EXCEPTION,
                MessageKey::OPERATION_EXCEPTION,
                context: [
                    ...$context,
                    'exception' => $error::class,
                    'message' => $error->getMessage(),
                ],
                level: MessageLevel::Error,
            ),
        ], $context, $messages);
    }
}
