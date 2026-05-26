<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\BackendAccessGuard;
use App\Backend\BackendActions;
use App\Backend\BackendArea;
use App\Backend\BackendRouteResolver;
use App\Backend\BackendViewDefinition;
use App\Backend\PackageLifecycleAdmin;
use App\Core\Access\AccessActor;
use App\Core\Config\Settings\CoreSettingsFormHandler;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Package\Settings\PackageSettingsFormHandler;
use App\Core\Workflow\WorkflowResult;
use App\Entity\UserAccount;
use App\Form\FormSubmissionResult;
use App\Navigation\NavigationBuilder;
use App\Setup\SetupRunner;
use App\Setup\SetupWebInputFactory;
use App\View\Http\HttpErrorRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class BackendController extends AbstractController
{
    public function __construct(
        private readonly BackendRouteResolver $routeResolver,
        private readonly BackendAccessGuard $accessGuard,
        private readonly NavigationBuilder $navigationBuilder,
        private readonly HttpErrorRenderer $httpError,
        private readonly CoreSettingsFormHandler $coreSettingsFormHandler,
        private readonly PackageSettingsFormHandler $packageSettingsFormHandler,
        private readonly BackendActions $backendActions,
        private readonly PackageLifecycleAdmin $packageLifecycleAdmin,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly SetupRunner $setupRunner,
        private readonly SetupWebInputFactory $setupWebInputFactory,
    ) {
    }

    #[Route('/setup', name: 'backend_setup_index', methods: ['GET', 'POST'])]
    public function setupIndex(Request $request): Response
    {
        return $this->handle($request, BackendArea::Setup);
    }

    #[Route('/setup/{path}', name: 'backend_setup_route', requirements: ['path' => '.+'], methods: ['GET'])]
    public function setupRoute(Request $request, string $path): Response
    {
        return $this->handle($request, BackendArea::Setup, $path);
    }

    #[Route('/admin', name: 'backend_admin_index', methods: ['GET', 'POST'])]
    public function adminIndex(Request $request): Response
    {
        return $this->handle($request, BackendArea::Admin);
    }

    #[Route('/admin/packages/{packageName}', name: 'backend_admin_package_detail', requirements: ['packageName' => '[^/]+'], methods: ['GET', 'POST'])]
    public function packageDetail(Request $request, string $packageName): Response
    {
        $access = $this->adminAccessResponse($request);

        if (null !== $access) {
            return $access;
        }

        if ($request->isMethod('POST')) {
            if ($this->isBackendActionRequest($request)) {
                return $this->handleBackendAction($request);
            }

            return $this->httpError->render(Response::HTTP_METHOD_NOT_ALLOWED, $request, context: [
                'area' => BackendArea::Admin->value,
                'package' => $packageName,
            ]);
        }

        $package = $this->packageLifecycleAdmin->package($packageName);

        if (null === $package) {
            return $this->httpError->render(Response::HTTP_NOT_FOUND, $request, context: [
                'area' => BackendArea::Admin->value,
                'package' => $packageName,
            ]);
        }

        return $this->render('@backend/admin/packages/detail.html.twig', [
            'area' => BackendArea::Admin,
            'navigation' => $this->navigation($request, BackendArea::Admin),
            'package' => $package,
        ]);
    }

    #[Route('/admin/packages/{packageName}/{action}', name: 'backend_admin_package_lifecycle', requirements: ['packageName' => '[^/]+', 'action' => 'activate|deactivate|reset-fault|purge|delete'], methods: ['GET', 'POST'])]
    public function packageLifecycle(Request $request, string $packageName, string $action): Response
    {
        $access = $this->adminAccessResponse($request);

        if (null !== $access) {
            return $access;
        }

        if ($request->isMethod('POST') && $this->isBackendActionRequest($request)) {
            return $this->handleBackendAction($request);
        }

        $review = $this->packageLifecycleAdmin->review($packageName, $action);

        if (null === $review['package']) {
            return $this->httpError->render(Response::HTTP_NOT_FOUND, $request, context: [
                'area' => BackendArea::Admin->value,
                'package' => $packageName,
                'action' => $action,
            ]);
        }

        if ($request->isMethod('POST')) {
            $formId = 'package-lifecycle-'.$action.'-'.$packageName;

            if (!$this->validFormToken($formId, $this->stringField($request, '_form_id'), $this->stringField($request, '_csrf_token'))) {
                $this->addFlash('error', [
                    'translation_key' => MessageKey::BACKEND_ACTION_INVALID_CSRF,
                    'parameters' => [],
                ]);

                return $this->redirect($request->getPathInfo());
            }

            $result = $this->packageLifecycleAdmin->apply($packageName, $action);
            $this->flashResult($result);

            if ($result->isSuccess()) {
                return $this->redirect('purge' === $action ? '/admin/packages' : '/admin/packages/'.rawurlencode($packageName));
            }

            return $this->redirect($request->getPathInfo());
        }

        return $this->render('@backend/admin/packages/lifecycle.html.twig', [
            'area' => BackendArea::Admin,
            'navigation' => $this->navigation($request, BackendArea::Admin),
            'review' => $review,
        ]);
    }

    #[Route('/admin/{path}', name: 'backend_admin_route', requirements: ['path' => '.+'], methods: ['GET', 'POST'])]
    public function adminRoute(Request $request, string $path): Response
    {
        if ($request->isMethod('GET') && 'settings' === trim($path, '/')) {
            return $this->redirectToRoute('backend_admin_route', ['path' => 'settings/general']);
        }

        return $this->handle($request, BackendArea::Admin, $path);
    }

    #[Route('/editor', name: 'backend_editor_index', methods: ['GET'])]
    public function editorIndex(Request $request): Response
    {
        return $this->handle($request, BackendArea::Editor);
    }

    #[Route('/editor/{path}', name: 'backend_editor_route', requirements: ['path' => '.+'], methods: ['GET'])]
    public function editorRoute(Request $request, string $path): Response
    {
        return $this->handle($request, BackendArea::Editor, $path);
    }

    private function handle(Request $request, BackendArea $area, string $path = ''): Response
    {
        $actor = $this->actor();
        $decision = $this->accessGuard->decide($area, $this->getUser());

        if (!$decision->isGranted()) {
            return $this->httpError->render(Response::HTTP_UNAUTHORIZED, $request, context: [
                'area' => $area->value,
                'access_decision' => $decision->toArray(),
            ]);
        }

        if (BackendArea::Admin === $area && $request->isMethod('POST') && $this->isBackendActionRequest($request)) {
            return $this->handleBackendAction($request);
        }

        $result = $this->routeResolver->resolve($area, $path);
        $view = $result->view();

        if (null !== $view && !$this->viewAllows($view, $actor)) {
            return $this->httpError->render(Response::HTTP_UNAUTHORIZED, $request, context: [
                'area' => $area->value,
                'view' => $view->uid(),
            ]);
        }

        if (BackendArea::Admin === $area && $request->isMethod('POST') && null !== $view) {
            $response = $this->handleAdminPost($request, $view);

            if (null !== $response) {
                return $response;
            }
        }

        $templateVariables = [
            'area' => $result->area(),
            'view' => $result->view(),
            'message' => $result->message()?->toArray(),
            'navigation' => $this->navigation($request, $area),
        ];

        if (BackendArea::Setup === $area && '' === trim($path, '/')) {
            $templateVariables += $this->setupVariables($request);
        }

        return $this->render($result->template(), $templateVariables, new Response(status: $result->statusCode()));
    }

    private function adminAccessResponse(Request $request): ?Response
    {
        $decision = $this->accessGuard->decide(BackendArea::Admin, $this->getUser());

        if ($decision->isGranted()) {
            return null;
        }

        return $this->httpError->render(Response::HTTP_UNAUTHORIZED, $request, context: [
            'area' => BackendArea::Admin->value,
            'access_decision' => $decision->toArray(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function navigation(Request $request, BackendArea $area): array
    {
        if (BackendArea::Setup === $area) {
            return [];
        }

        return $this->navigationBuilder->build(
            $area->navigationIdentifier(),
            (string) $request->getLocale(),
            actor: $this->actor(),
            activeUrl: $request->getPathInfo(),
            activeRoute: (string) $request->attributes->get('_route'),
        );
    }

    private function actor(): AccessActor
    {
        $user = $this->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }

    private function viewAllows(BackendViewDefinition $view, AccessActor $actor): bool
    {
        if ($actor->accessLevel() >= $view->minimumAccessLevel()) {
            return true;
        }

        foreach ($view->accessGroups() as $group) {
            if ($actor->hasGroupIdentifier($group)) {
                return true;
            }
        }

        return false;
    }

    private function handleAdminPost(Request $request, BackendViewDefinition $view): ?Response
    {
        $context = $view->context();
        $formId = $this->stringField($request, '_form_id');
        $token = $this->stringField($request, '_csrf_token');
        $result = null;
        $expectedFormId = null;

        if ($this->isBackendActionRequest($request)) {
            return $this->handleBackendAction($request);
        }

        if (isset($context['settings_section']) && is_string($context['settings_section'])) {
            $expectedFormId = 'admin-settings-'.$context['settings_section'];
            $result = $this->validFormToken($expectedFormId, $formId, $token)
                ? $this->coreSettingsFormHandler->submit($context['settings_section'], $request->request->all(), $this->actor()->userUid())
                : $this->invalidCsrfResult($request);
        } elseif ('backend-admin-settings-packages' === $view->uid()) {
            $expectedFormId = 'admin-settings-packages';
            $result = $this->validFormToken($expectedFormId, $formId, $token)
                ? $this->coreSettingsFormHandler->submit('packages', $request->request->all(), $this->actor()->userUid())
                : $this->invalidCsrfResult($request);
        } elseif (isset($context['package_name']) && is_string($context['package_name'])) {
            $expectedFormId = 'package-settings-'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($context['package_name']));
            $result = $this->validFormToken($expectedFormId, $formId, $token)
                ? $this->packageSettingsFormHandler->submit($context['package_name'], $request->request->all(), $this->actor()->userUid())
                : $this->invalidCsrfResult($request);
        }

        if (!$result instanceof FormSubmissionResult) {
            return $this->httpError->render(Response::HTTP_METHOD_NOT_ALLOWED, $request, context: [
                'area' => $view->area()->value,
                'view' => $view->uid(),
            ]);
        }

        if ($result->isValid()) {
            $this->addFlash('success', 'admin.settings.form.saved');

            return $this->redirect($request->getPathInfo());
        }

        $request->attributes->set('_studio_form_values', $result->values());
        $request->attributes->set('_studio_form_errors', $result->errors());

        return null;
    }

    private function handleBackendAction(Request $request): Response
    {
        $action = $this->stringField($request, '_backend_action');
        $formId = $this->stringField($request, '_form_id');
        $token = $this->stringField($request, '_csrf_token');
        $result = $this->validFormToken('backend-action-'.$action, $formId, $token)
            ? $this->backendActions->run($action)
            : WorkflowResult::invalid([
                Message::warning(
                    MessageCode::E_INVALID_ARGUMENT,
                    MessageKey::BACKEND_ACTION_INVALID_CSRF,
                    context: ['action' => $action],
                ),
            ], ['action' => $action]);

        $this->flashResult($result);

        return $this->redirect($request->getPathInfo());
    }

    private function isBackendActionRequest(Request $request): bool
    {
        return '' !== $this->stringField($request, '_backend_action');
    }

    private function validFormToken(string $expectedFormId, string $formId, string $token): bool
    {
        return $expectedFormId === $formId && $this->csrfTokenManager->isTokenValid(new CsrfToken($expectedFormId, $token));
    }

    private function invalidCsrfResult(Request $request): FormSubmissionResult
    {
        return new FormSubmissionResult($request->request->all(), [
            '__form' => ['admin.settings.form.errors.invalid_csrf'],
        ]);
    }

    /**
     * @param WorkflowResult<mixed> $result
     */
    private function flashResult(WorkflowResult $result): void
    {
        $message = $result->isSuccess()
            ? ($result->messages()[0] ?? Message::success(MessageKey::BACKEND_ACTION_CACHE_CLEAR_COMPLETED))
            : ($result->firstIssue() ?? Message::error(MessageCode::E_OPERATION_FAILED, MessageKey::OPERATION_EXCEPTION));

        $this->addFlash($result->isSuccess() ? 'success' : 'error', [
            'translation_key' => $message->translationKey(),
            'parameters' => $message->parameters(),
        ]);
    }

    private function stringField(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function setupVariables(Request $request): array
    {
        $values = $this->setupWebInputFactory->defaults();
        $errors = [];
        $workflow = null;
        $actionLog = null;

        if ($request->isMethod('POST')) {
            if (!$this->validFormToken('setup-web', $this->stringField($request, '_form_id'), $this->stringField($request, '_csrf_token'))) {
                $values = array_replace($values, $request->request->all());
                $errors['__form'] = ['setup.form.errors.invalid_csrf'];
            } else {
                $inputResult = $this->setupWebInputFactory->create($request->request->all());
                $values = $inputResult->values();
                $errors = $inputResult->errors();

                if ($inputResult->isValid() && null !== $inputResult->input()) {
                    $result = $this->setupRunner->run($inputResult->input());
                    $workflow = $result->toArray();
                    $actionLog = $result->value()?->toArray() ?? $result->context()['action_log'] ?? null;
                }
            }
        }

        return [
            'setup_values' => $values,
            'setup_errors' => $errors,
            'setup_available_languages' => $this->setupWebInputFactory->availableLanguages(),
            'setup_database_driver_options' => $this->setupWebInputFactory->databaseDriverOptions(),
            'setup_workflow' => $workflow,
            'setup_action_log' => $actionLog,
        ];
    }
}
