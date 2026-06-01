<?php

declare(strict_types=1);

namespace App\Controller;

use App\Scheduler\SchedulerApiAuthenticator;
use App\Scheduler\SchedulerRunner;
use App\Scheduler\SchedulerTaskRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class SchedulerController extends AbstractController
{
    public function __construct(
        private readonly SchedulerApiAuthenticator $authenticator,
        private readonly SchedulerRunner $runner,
        private readonly SchedulerTaskRegistry $registry,
    ) {
    }

    #[Route('/cron/run', name: 'scheduler_cron_run', methods: ['GET', 'POST'])]
    public function run(Request $request): JsonResponse
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

        if (null !== $job && null === $this->registry->definition($job)) {
            return new JsonResponse([
                'status' => 'not_found',
                'job' => $job,
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        $result = $this->runner->run($job, null !== $job);
        $payload = $result->toArray();
        $payload['auth'] = [
            'api_key_prefix' => $apiKey->prefix(),
        ];

        return new JsonResponse($payload, 'disabled' === $payload['status'] ? JsonResponse::HTTP_FORBIDDEN : JsonResponse::HTTP_OK);
    }
}
