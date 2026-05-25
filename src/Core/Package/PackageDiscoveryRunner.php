<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Message\Message;
use App\Core\Workflow\WorkflowResult;
use Throwable;

final readonly class PackageDiscoveryRunner
{
    public function __construct(
        private PackageDiscovery $discovery,
        private PackageRegistryHandler $registryHandler,
        private string $projectDir,
        private string $environment,
        private WorkflowResultMessageReporterInterface $messageReporter,
    ) {
    }

    /**
     * @return WorkflowResult<array{candidate_count: int, change_count: int, changes: list<array{package: string, action: string, status: string}>}>
     */
    public function __invoke(string $trigger = 'manual'): WorkflowResult
    {
        $trigger = '' === trim($trigger) ? 'manual' : trim($trigger);
        $baseContext = [
            'trigger' => $trigger,
            'environment' => $this->environment,
        ];

        try {
            $discoveryResult = $this->discovery->discover($this->projectDir, $this->environment);
        } catch (Throwable $error) {
            return $this->report($this->exceptionResult($error, $baseContext), $baseContext);
        }

        $candidates = $this->candidatesFrom($discoveryResult);
        $messages = $discoveryResult->messages();
        $context = [
            ...$baseContext,
            'candidate_count' => count($candidates),
        ];

        if (!$discoveryResult->isSuccess()) {
            return $this->report(WorkflowResult::invalid($discoveryResult->issues(), $context, $messages), $context);
        }

        try {
            $registryResult = $this->registryHandler->synchronize($candidates);
        } catch (Throwable $error) {
            return $this->report($this->exceptionResult($error, $context, $messages), $context);
        }

        $messages = [
            ...$messages,
            ...$registryResult->messages(),
        ];

        if (!$registryResult->isSuccess()) {
            return $this->report(WorkflowResult::invalid($registryResult->issues(), [
                ...$context,
                'change_count' => count($registryResult->value() ?? []),
                'changes' => $registryResult->value() ?? [],
            ], $messages), $context);
        }

        $changes = $registryResult->value() ?? [];

        return $this->report(WorkflowResult::success([
            'candidate_count' => count($candidates),
            'change_count' => count($changes),
            'changes' => $changes,
        ], [
            ...$context,
            'change_count' => count($changes),
            'changes' => $changes,
        ], $messages), $context);
    }

    /**
     * @return list<PackageCandidate>
     */
    private function candidatesFrom(WorkflowResult $result): array
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
     * @return WorkflowResult<null>
     */
    private function exceptionResult(Throwable $error, array $context, array $messages = []): WorkflowResult
    {
        return WorkflowResult::failed([
            Message::exception(
                MessageCode::OPERATION_EXCEPTION,
                MessageKey::OPERATION_EXCEPTION,
                context: [
                    ...$context,
                    'exception' => $error::class,
                    'message' => $error->getMessage(),
                ],
            ),
        ], $context, $messages);
    }

    private function report(WorkflowResult $result, array $context): WorkflowResult
    {
        return $this->messageReporter->report($result, [
            ...$context,
            'operation' => 'package.discovery.run',
        ]);
    }
}
