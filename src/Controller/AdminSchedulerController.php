<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\AdminControllerContext;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Entity\SchedulerTask;
use App\Entity\SchedulerTaskRun;
use App\Scheduler\SchedulerCron;
use App\Scheduler\SchedulerSettings;
use App\Scheduler\SchedulerRunner;
use App\Scheduler\SchedulerTaskStatus;
use App\Scheduler\SchedulerTaskType;
use App\Scheduler\SchedulerTaskSynchronizer;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\UiAlertTranslation;
use App\View\Http\HttpErrorRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AdminSchedulerController extends AbstractController
{
    private const FEATURE = 'admin.scheduler';

    public function __construct(
        private readonly AdminControllerContext $adminContext,
        private readonly SchedulerTaskSynchronizer $synchronizer,
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulerSettings $settings,
        private readonly SchedulerRunner $runner,
        private readonly HttpErrorRenderer $httpError,
        private readonly UiAlertDispatcherInterface $alerts,
        private readonly AdminFeatureAccessPolicy $adminAcl,
    ) {
    }

    #[Route('/admin/scheduler/{identifier}/run', name: 'backend_admin_scheduler_run', requirements: ['identifier' => '[A-Za-z0-9_.:-]+'], priority: 10, methods: ['POST'])]
    public function runNow(Request $request, string $identifier): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }
        if ($response = $this->featureResponse($request, mutable: true)) {
            return $response;
        }

        $token = $request->request->get('_csrf_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('scheduler-task-run-'.$identifier, $token)) {
            $this->alertKey('error', 'admin.scheduler.form.errors.invalid_csrf');

            return $this->redirectToRoute('backend_admin_scheduler_detail', ['identifier' => $identifier]);
        }

        $task = $this->registeredTask($identifier);
        if (!$task instanceof SchedulerTask) {
            return $this->httpError->resolve(Response::HTTP_NOT_FOUND, $request, context: [
                'task' => $identifier,
            ]);
        }

        if (SchedulerTaskStatus::Active !== $task->status()) {
            $this->alertKey('error', 'admin.scheduler.actions.run_now_inactive');

            return $this->redirectToRoute('backend_admin_scheduler_detail', ['identifier' => $identifier]);
        }

        $this->flashRunNowResult($this->runner->run($identifier, true)->toArray());

        return $this->redirectToRoute('backend_admin_scheduler_detail', ['identifier' => $identifier]);
    }

    #[Route('/admin/scheduler', name: 'backend_admin_scheduler', priority: 10, methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }
        if ($response = $this->featureResponse($request, mutable: false)) {
            return $response;
        }

        $tasks = $this->synchronizer->synchronize();
        usort($tasks, static fn (SchedulerTask $left, SchedulerTask $right): int => $left->identifier() <=> $right->identifier());

        return $this->render('@backend/admin/scheduler/index.html.twig', [
            'navigation' => $this->adminContext->navigation($request, $this->getUser()),
            'tasks' => $tasks,
            'cron_run_url' => $this->generateUrl('scheduler_cron_run', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'get_auth_enabled' => $this->settings->getAuthEnabled(),
        ]);
    }

    #[Route('/admin/scheduler/{identifier}', name: 'backend_admin_scheduler_detail', requirements: ['identifier' => '[A-Za-z0-9_.:-]+'], priority: 10, methods: ['GET', 'POST'])]
    public function detail(Request $request, string $identifier): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }
        if ($response = $this->featureResponse($request, mutable: $request->isMethod('POST'))) {
            return $response;
        }

        $task = $this->registeredTask($identifier);

        if (!$task instanceof SchedulerTask) {
            return $this->httpError->resolve(Response::HTTP_NOT_FOUND, $request, context: [
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
            'cron_run_url' => $this->generateUrl('scheduler_cron_run', ['job' => $task->identifier()], UrlGeneratorInterface::ABSOLUTE_URL),
            'scheduler_mutable' => $this->adminAcl->isMutable(self::FEATURE, $this->adminContext->actor($this->getUser())),
        ]);
    }

    private function featureResponse(Request $request, bool $mutable): ?Response
    {
        $actor = $this->adminContext->actor($this->getUser());
        $allowed = $mutable
            ? $this->adminAcl->isMutable(self::FEATURE, $actor)
            : $this->adminAcl->isVisible(self::FEATURE, $actor);

        if ($allowed) {
            return null;
        }

        return $this->httpError->resolve(Response::HTTP_UNAUTHORIZED, $request, context: [
            'feature' => self::FEATURE,
            'required_state' => $mutable ? 'mutable' : 'visible',
        ]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function flashRunNowResult(array $result): void
    {
        $status = is_string($result['status'] ?? null) ? $result['status'] : 'unknown';

        if ('locked' === $status) {
            $this->alertKey('warning', 'admin.scheduler.actions.run_now_locked');

            return;
        }

        if ('completed' !== $status) {
            $this->alertKey('warning', 'admin.scheduler.actions.run_now_unavailable', ['%status%' => $status]);

            return;
        }

        $taskStatuses = array_values(array_filter(array_map(
            static fn (mixed $task): ?string => is_array($task) && is_string($task['status'] ?? null) ? $task['status'] : null,
            is_array($result['tasks'] ?? null) ? $result['tasks'] : [],
        )));

        if (in_array('failed', $taskStatuses, true)) {
            $this->alertKey('error', 'admin.scheduler.actions.run_now_failed', ['%status%' => $status]);

            return;
        }

        if ([] === $taskStatuses || in_array('skipped', $taskStatuses, true)) {
            $this->alertKey('warning', 'admin.scheduler.actions.run_now_skipped', ['%status%' => $status]);

            return;
        }

        $this->alertKey('success', 'admin.scheduler.actions.run_now_started', ['%status%' => $status]);
    }

    private function handleUpdate(Request $request, SchedulerTask $task): void
    {
        $token = $request->request->get('_csrf_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('scheduler-task-'.$task->identifier(), $token)) {
            $this->alertKey('error', 'admin.scheduler.form.errors.invalid_csrf');

            return;
        }

        $cronExpression = $request->request->get('cron_expression');
        $cronExpression = is_string($cronExpression) ? trim($cronExpression) : '';

        if (!SchedulerCron::isValid($cronExpression)) {
            $this->alertKey('error', 'admin.scheduler.form.errors.cron_invalid');

            return;
        }

        $enabled = '1' === $request->request->get('enabled');
        if ($enabled && !$task->trusted() && SchedulerTaskType::ActionQueue === $task->type() && '1' !== $request->request->get('confirm_extension_action_queue')) {
            $this->alertKey('error', 'admin.scheduler.form.errors.extension_action_queue_confirmation_required');

            return;
        }

        if ($enabled) {
            $task->activate($cronExpression);
        } else {
            $task->deactivate();
        }

        $this->entityManager->flush();
        $this->alertKey('success', 'admin.scheduler.form.saved');
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

    /**
     * @param array<string, mixed> $parameters
     */
    private function alertKey(string $level, string $key, array $parameters = []): void
    {
        $this->alerts->addAlert(UiAlertTranslation::forLevel($level, $key, $parameters), UiAlertDelivery::Direct);
    }
}
