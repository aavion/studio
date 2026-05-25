<?php

declare(strict_types=1);

namespace App\Core\Message;

use App\Core\ActionLog\ActionLog;
use App\Core\ActionLog\ActionLogEntry;
use App\Core\Workflow\WorkflowResult;
use WeakMap;

final readonly class WorkflowResultMessageReporter implements WorkflowResultMessageReporterInterface
{
    /**
     * @var WeakMap<WorkflowResult<mixed>, true>
     */
    private WeakMap $reportedResults;

    public function __construct(private MessageReporterInterface $messageReporter)
    {
        $this->reportedResults = new WeakMap();
    }

    public function report(WorkflowResult $result, array $operationContext = []): WorkflowResult
    {
        if (isset($this->reportedResults[$result])) {
            return $result;
        }

        $this->reportedResults[$result] = true;
        $records = [];

        foreach ($result->issues() as $issue) {
            $records[] = [
                'message' => $issue,
                'context' => $this->resultContext($result, $operationContext, 'issue'),
            ];
        }

        foreach ($result->messages() as $message) {
            $records[] = [
                'message' => $message,
                'context' => $this->resultContext($result, $operationContext, 'message'),
            ];
        }

        $value = $result->value();

        if ($value instanceof ActionLog) {
            foreach ($value->entries() as $entry) {
                foreach ($entry->issues() as $issue) {
                    $records[] = [
                        'message' => $issue,
                        'context' => $this->actionLogContext($result, $operationContext, $entry, 'action_log_issue'),
                    ];
                }

                foreach ($entry->messages() as $message) {
                    $records[] = [
                        'message' => $message,
                        'context' => $this->actionLogContext($result, $operationContext, $entry, 'action_log_message'),
                    ];
                }
            }
        }

        $this->messageReporter->reportBatch($records);

        return $result;
    }

    /**
     * @param WorkflowResult<mixed> $result
     * @param array<string, mixed> $operationContext
     *
     * @return array<string, mixed>
     */
    private function resultContext(WorkflowResult $result, array $operationContext, string $kind): array
    {
        return [
            'kind' => $kind,
            'result_status' => $result->status()->value,
            'result_context' => $result->context(),
            'operation_context' => $operationContext,
        ];
    }

    /**
     * @param WorkflowResult<mixed> $result
     * @param array<string, mixed> $operationContext
     *
     * @return array<string, mixed>
     */
    private function actionLogContext(WorkflowResult $result, array $operationContext, ActionLogEntry $entry, string $kind): array
    {
        return [
            ...$this->resultContext($result, $operationContext, $kind),
            'action_log_entry' => [
                'name' => $entry->name(),
                'status' => $entry->status()->value,
                'started_at' => $entry->startedAt()?->format(DATE_ATOM),
                'finished_at' => $entry->finishedAt()?->format(DATE_ATOM),
                'duration_ms' => $entry->durationMilliseconds(),
                'context' => $entry->context(),
            ],
        ];
    }
}
