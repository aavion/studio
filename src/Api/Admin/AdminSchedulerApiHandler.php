<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiJsonRequestParser;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Entity\SchedulerTask;
use App\Entity\SchedulerTaskRun;
use App\Scheduler\SchedulerCron;
use App\Scheduler\SchedulerRunner;
use App\Scheduler\SchedulerTaskStatus;
use App\Scheduler\SchedulerTaskType;
use App\Scheduler\SchedulerTaskSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AdminSchedulerApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private SchedulerTaskSynchronizer $tasks,
        private SchedulerRunner $runner,
        private EntityManagerInterface $entityManager,
        private ApiJsonRequestParser $jsonRequests,
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

        $taskIdentifier = $this->taskIdentifierFromPath($request->getPathInfo());
        if (null !== $taskIdentifier) {
            $task = $this->task($taskIdentifier);
            if (!$task instanceof SchedulerTask) {
                return $this->notFound($request, $taskIdentifier);
            }

            if (str_ends_with($request->getPathInfo(), '/run')) {
                return $this->runTask($request, $task);
            }

            if ($request->isMethod(Request::METHOD_PATCH)) {
                return $this->updateTask($request, $task);
            }

            return $this->responder->data($this->resource($task, includeRuns: true));
        }

        $tasks = $this->tasks->synchronize();
        usort($tasks, static fn (SchedulerTask $left, SchedulerTask $right): int => $left->identifier() <=> $right->identifier());
        $resources = array_map($this->resource(...), $tasks);

        return $this->responder->data($resources, meta: ['count' => count($resources)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function runTask(Request $request, SchedulerTask $task): Response
    {
        if (SchedulerTaskStatus::Active !== $task->status()) {
            return $this->operationUnavailable($request, 'runAdminSchedulerTask', [
                'task_identifier' => $task->identifier(),
                'reason' => 'task_inactive',
            ]);
        }

        return $this->responder->data([
            'type' => 'scheduler_run',
            'id' => $task->identifier(),
            'attributes' => $this->runner->run($task->identifier(), true)->toArray(),
            'links' => [
                'task' => '/api/v1/admin/scheduler/'.$task->identifier(),
            ],
        ]);
    }

    private function updateTask(Request $request, SchedulerTask $task): Response
    {
        try {
            $payload = $this->jsonRequests->object($request);
        } catch (JsonException $error) {
            return $this->invalidRequest($request, $error->getMessage());
        }

        $errors = [];
        $enabled = $payload['enabled'] ?? SchedulerTaskStatus::Active === $task->status();
        if (!is_bool($enabled)) {
            $errors['enabled'] = ['api.scheduler.enabled_boolean_required'];
        }

        $cronExpression = $payload['cron_expression'] ?? $task->cronExpression();
        if (!is_string($cronExpression) || !SchedulerCron::isValid(trim($cronExpression))) {
            $errors['cron_expression'] = ['admin.scheduler.form.errors.cron_invalid'];
        }

        $confirmPackageActionQueue = true === ($payload['confirm_package_action_queue'] ?? false);
        if (
            true === $enabled
            && !$task->trusted()
            && SchedulerTaskType::ActionQueue === $task->type()
            && !$confirmPackageActionQueue
        ) {
            $errors['confirm_package_action_queue'] = ['admin.scheduler.form.errors.package_action_queue_confirmation_required'];
        }

        if ([] !== $errors) {
            return $this->validationFailed($request, $errors, ['task_identifier' => $task->identifier()]);
        }

        if ($enabled) {
            $task->activate(trim($cronExpression));
        } else {
            $task->deactivate();
        }

        $this->entityManager->flush();

        return $this->responder->data($this->resource($task, includeRuns: true), meta: [
            'updated_fields' => array_values(array_intersect(['enabled', 'cron_expression'], array_keys($payload))),
        ]);
    }

    private function taskIdentifierFromPath(string $path): ?string
    {
        if (1 !== preg_match('#^/api/v1/admin/scheduler/([A-Za-z0-9_.:-]+)(?:/run)?$#', $path, $matches)) {
            return null;
        }

        return rawurldecode($matches[1]);
    }

    private function task(string $identifier): ?SchedulerTask
    {
        foreach ($this->tasks->synchronize($identifier) as $task) {
            if ($task->identifier() === $identifier) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(SchedulerTask $task, bool $includeRuns = false): array
    {
        $resource = [
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
            'links' => [
                'self' => '/api/v1/admin/scheduler/'.$task->identifier(),
                'run' => '/api/v1/admin/scheduler/'.$task->identifier().'/run',
            ],
        ];

        if ($includeRuns) {
            $resource['relationships'] = [
                'recent_runs' => array_map($this->runResource(...), $this->recentRuns($task)),
            ];
        }

        return $resource;
    }

    /**
     * @return list<SchedulerTaskRun>
     */
    private function recentRuns(SchedulerTask $task): array
    {
        return $this->entityManager->getRepository(SchedulerTaskRun::class)->findBy(
            ['task' => $task],
            ['startedAt' => 'DESC'],
            20,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runResource(SchedulerTaskRun $run): array
    {
        return [
            'type' => 'scheduler_task_run',
            'id' => $run->uid(),
            'attributes' => [
                'status' => $run->status()->value,
                'started_at' => $run->startedAt()->format(DATE_ATOM),
                'finished_at' => $run->finishedAt()?->format(DATE_ATOM),
                'duration_ms' => $run->durationMs(),
                'context' => $run->context(),
            ],
        ];
    }

    private function invalidRequest(Request $request, string $reason): Response
    {
        return $this->responder->error(
            Message::warning(CommonMessageCode::E_INVALID_ARGUMENT, ApiMessageKey::API_REQUEST_INVALID, context: [
                'path' => $request->getPathInfo(),
                'reason' => $reason,
            ]),
            Response::HTTP_BAD_REQUEST,
            $request,
        );
    }

    private function notFound(Request $request, string $identifier): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_ENDPOINT_NOT_FOUND, ApiMessageKey::API_ENDPOINT_NOT_FOUND, context: [
                'path' => $request->getPathInfo(),
                'task_identifier' => $identifier,
            ]),
            Response::HTTP_NOT_FOUND,
            $request,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function operationUnavailable(Request $request, string $operation, array $context): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_OPERATION_UNAVAILABLE, ApiMessageKey::API_OPERATION_UNAVAILABLE, [
                '%operation%' => $operation,
            ], $context),
            Response::HTTP_CONFLICT,
            $request,
        );
    }

    /**
     * @param array<string, mixed> $errors
     * @param array<string, mixed> $context
     */
    private function validationFailed(Request $request, array $errors, array $context): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_VALIDATION_FAILED, ApiMessageKey::API_VALIDATION_FAILED, context: [
                ...$context,
                'path' => $request->getPathInfo(),
                'errors' => $errors,
            ]),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $request,
        );
    }
}
