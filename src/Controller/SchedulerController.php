<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Scheduler\SchedulerApiAuthenticator;
use App\Scheduler\SchedulerMessageCode;
use App\Scheduler\SchedulerMessageKey;
use App\Scheduler\SchedulerRunner;
use App\Scheduler\SchedulerTaskDefinition;
use App\Scheduler\SchedulerTaskRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class SchedulerController extends AbstractController
{
    public function __construct(
        private readonly SchedulerApiAuthenticator $authenticator,
        private readonly SchedulerRunner $runner,
        private readonly SchedulerTaskRegistry $registry,
        private readonly MessageLoggerInterface $messageLogger,
    ) {
    }

    #[Route('/cron/run', name: 'scheduler_cron_run', methods: ['GET', 'POST'])]
    public function run(Request $request): JsonResponse
    {
        try {
            return $this->runScheduler($request);
        } catch (Throwable $error) {
            $job = $request->query->get('job');
            $this->messageLogger->log(Message::exception(
                SchedulerMessageCode::SCHEDULER_RUN_FAILED,
                SchedulerMessageKey::SCHEDULER_RUN_FAILED,
                context: [
                    'exception' => $error::class,
                    'message' => $error->getMessage(),
                    'job' => is_string($job) ? substr($job, 0, 160) : null,
                ],
            ));

            return new JsonResponse(['status' => 'unavailable'], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function runScheduler(Request $request): JsonResponse
    {
        $apiKey = $this->authenticator->authenticate($request);

        if (null === $apiKey) {
            return new JsonResponse([
                'status' => 'unauthorized',
                'auth' => $this->authenticator->redactedTokenSubject($request),
            ], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $job = $request->query->get('job');
        $job = is_string($job) && '' !== trim($job) ? trim($job) : null;

        if (null !== $job && (!SchedulerTaskDefinition::isValidIdentifier($job) || null === $this->registry->definition($job))) {
            return new JsonResponse([
                'status' => 'not_found',
                'job' => SchedulerTaskDefinition::isValidIdentifier($job) ? $job : 'invalid',
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        $result = $this->runner->run($job, null !== $job);
        $payload = $result->toArray();
        $payload['auth'] = [
            'api_key_prefix' => $apiKey->prefix(),
        ];
        $statusCode = match ($payload['status']) {
            'disabled' => JsonResponse::HTTP_FORBIDDEN,
            'locked' => JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            default => $this->hasFailedTask($payload) ? JsonResponse::HTTP_INTERNAL_SERVER_ERROR : JsonResponse::HTTP_OK,
        };
        if (JsonResponse::HTTP_OK === $statusCode && null !== $job && $this->hasNonSuccessfulTask($payload)) {
            $statusCode = JsonResponse::HTTP_CONFLICT;
        }
        $response = new JsonResponse($payload, $statusCode);
        if ('locked' === $payload['status']) {
            $response->headers->set('Retry-After', '60');
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasFailedTask(array $payload): bool
    {
        foreach (($payload['tasks'] ?? []) as $task) {
            if (is_array($task) && 'failed' === ($task['status'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasNonSuccessfulTask(array $payload): bool
    {
        foreach (($payload['tasks'] ?? []) as $task) {
            if (is_array($task) && 'success' !== ($task['status'] ?? null)) {
                return true;
            }
        }

        return [] === ($payload['tasks'] ?? []);
    }
}
