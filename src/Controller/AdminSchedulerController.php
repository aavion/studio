<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\AdminControllerContext;
use App\Entity\SchedulerTask;
use App\Entity\SchedulerTaskRun;
use App\Scheduler\SchedulerCron;
use App\Scheduler\SchedulerSettings;
use App\Scheduler\SchedulerRunner;
use App\Scheduler\SchedulerTaskStatus;
use App\Scheduler\SchedulerTaskType;
use App\Scheduler\SchedulerTaskSynchronizer;
use App\View\Http\HttpErrorRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminSchedulerController extends AbstractController
{
    public function __construct(
        private readonly AdminControllerContext $adminContext,
        private readonly SchedulerTaskSynchronizer $synchronizer,
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulerSettings $settings,
        private readonly SchedulerRunner $runner,
        private readonly HttpErrorRenderer $httpError,
    ) {
    }

    #[Route('/admin/scheduler/{identifier}/run', name: 'backend_admin_scheduler_run', requirements: ['identifier' => '[A-Za-z0-9_.:-]+'], priority: 10, methods: ['POST'])]
    public function runNow(Request $request, string $identifier): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }

        $token = $request->request->get('_csrf_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('scheduler-task-run-'.$identifier, $token)) {
            $this->addFlash('error', ['translation_key' => 'admin.scheduler.form.errors.invalid_csrf', 'parameters' => []]);

            return $this->redirectToRoute('backend_admin_scheduler_detail', ['identifier' => $identifier]);
        }

        $task = $this->registeredTask($identifier);
        if (!$task instanceof SchedulerTask) {
            return $this->httpError->render(Response::HTTP_NOT_FOUND, $request, context: [
                'task' => $identifier,
            ]);
        }

        if (SchedulerTaskStatus::Active !== $task->status()) {
            $this->addFlash('error', ['translation_key' => 'admin.scheduler.actions.run_now_inactive', 'parameters' => []]);

            return $this->redirectToRoute('backend_admin_scheduler_detail', ['identifier' => $identifier]);
        }

        $result = $this->runner->run($identifier, true)->toArray();
        $this->addFlash('success', ['translation_key' => 'admin.scheduler.actions.run_now_started', 'parameters' => [
            '%status%' => $result['status'],
        ]]);

        return $this->redirectToRoute('backend_admin_scheduler_detail', ['identifier' => $identifier]);
    }

    #[Route('/admin/scheduler', name: 'backend_admin_scheduler', priority: 10, methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }

        $tasks = $this->synchronizer->synchronize();
        usort($tasks, static fn (SchedulerTask $left, SchedulerTask $right): int => $left->identifier() <=> $right->identifier());

        return $this->render('@backend/admin/scheduler/index.html.twig', [
            'navigation' => $this->adminContext->navigation($request, $this->getUser()),
            'tasks' => $tasks,
            'cron_run_url' => $request->getSchemeAndHttpHost().'/cron/run',
            'get_auth_enabled' => $this->settings->getAuthEnabled(),
        ]);
    }

    #[Route('/admin/scheduler/{identifier}', name: 'backend_admin_scheduler_detail', requirements: ['identifier' => '[A-Za-z0-9_.:-]+'], priority: 10, methods: ['GET', 'POST'])]
    public function detail(Request $request, string $identifier): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }

        $task = $this->registeredTask($identifier);

        if (!$task instanceof SchedulerTask) {
            return $this->httpError->render(Response::HTTP_NOT_FOUND, $request, context: [
                'task' => $identifier,
            ]);
        }

        if ($request->isMethod('POST')) {
            $this->handleUpdate($request, $task);

            return $this->redirectToRoute('backend_admin_scheduler_detail', ['identifier' => $identifier]);
        }

        return $this->render('@backend/admin/scheduler/detail.html.twig', [
            'navigation' => $this->adminContext->navigation($request, $this->getUser()),
            'task' => $task,
            'runs' => $this->recentRuns($task),
            'cron_run_url' => $request->getSchemeAndHttpHost().'/cron/run?job='.rawurlencode($task->identifier()),
        ]);
    }

    private function handleUpdate(Request $request, SchedulerTask $task): void
    {
        $token = $request->request->get('_csrf_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('scheduler-task-'.$task->identifier(), $token)) {
            $this->addFlash('error', ['translation_key' => 'admin.scheduler.form.errors.invalid_csrf', 'parameters' => []]);

            return;
        }

        $cronExpression = $request->request->get('cron_expression');
        $cronExpression = is_string($cronExpression) ? trim($cronExpression) : '';

        if (!SchedulerCron::isValid($cronExpression)) {
            $this->addFlash('error', ['translation_key' => 'admin.scheduler.form.errors.cron_invalid', 'parameters' => []]);

            return;
        }

        $enabled = '1' === $request->request->get('enabled');
        if ($enabled && !$task->trusted() && SchedulerTaskType::ActionQueue === $task->type() && '1' !== $request->request->get('confirm_package_action_queue')) {
            $this->addFlash('error', ['translation_key' => 'admin.scheduler.form.errors.package_action_queue_confirmation_required', 'parameters' => []]);

            return;
        }

        if ($enabled) {
            $task->activate($cronExpression);
        } else {
            $task->deactivate();
        }

        $this->entityManager->flush();
        $this->addFlash('success', ['translation_key' => 'admin.scheduler.form.saved', 'parameters' => []]);
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

    private function registeredTask(string $identifier): ?SchedulerTask
    {
        foreach ($this->synchronizer->synchronize() as $task) {
            if ($task->identifier() === $identifier) {
                return $task;
            }
        }

        return null;
    }
}
