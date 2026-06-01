<?php

declare(strict_types=1);

namespace App\Scheduler;

final readonly class SchedulerRunResult
{
    /**
     * @param list<array<string, mixed>> $tasks
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $status,
        private array $tasks = [],
        private array $context = [],
    ) {
    }

    /**
     * @return array{status: string, tasks: list<array<string, mixed>>, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'tasks' => $this->tasks,
            'context' => $this->context,
        ];
    }
}
