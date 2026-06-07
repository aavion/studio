<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Entity\SchedulerTask;
use App\Scheduler\SchedulerTaskSynchronizer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AdminSchedulerApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private SchedulerTaskSynchronizer $tasks,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return AdminOperationalApiEndpointProvider::HANDLER_SCHEDULER;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        $tasks = $this->tasks->synchronize();
        usort($tasks, static fn (SchedulerTask $left, SchedulerTask $right): int => $left->identifier() <=> $right->identifier());
        $resources = array_map($this->resource(...), $tasks);

        return $this->responder->data($resources, meta: ['count' => count($resources)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(SchedulerTask $task): array
    {
        return [
            'type' => 'scheduler_task',
            'id' => $task->identifier(),
            'attributes' => [
                'label_key' => $task->labelKey(),
                'description_key' => $task->descriptionKey(),
                'source' => $task->source(),
                'task_type' => $task->type()->value,
                'target' => $task->target(),
                'cron_expression' => $task->cronExpression(),
                'default_cron_expression' => $task->defaultCronExpression(),
                'status' => $task->status()->value,
                'trusted' => $task->trusted(),
                'next_due_at' => $task->nextDueAt()?->format(DATE_ATOM),
                'last_attempt_at' => $task->lastAttemptAt()?->format(DATE_ATOM),
                'last_success_at' => $task->lastSuccessAt()?->format(DATE_ATOM),
                'failure_count' => $task->failureCount(),
            ],
        ];
    }
}
