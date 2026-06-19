<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\AdminViewContextProvider;
use App\Backend\BackendAccessGuard;
use App\Backend\BackendActionResponder;
use App\Backend\BackendArea;
use App\Backend\BackendRouteResolver;
use App\Backend\BackendViewDefinition;
use App\Core\Access\AccessActor;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\AdminAcl\AdminAclSettingsFormHandler;
use App\Core\Config\Settings\CoreSettingsFormHandler;
use App\Core\Log\AdminLogBrowser;
use App\Core\Message\Message;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Extension\Settings\ExtensionSettingsFormHandler;
use App\Entity\UserAccount;
use App\Form\FormErrorKey;
use App\Form\FormSubmissionResult;
use App\Form\FormTokenValidator;
use App\Navigation\NavigationBuilder;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\UiAlertTranslation;
use App\View\Http\HttpErrorRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class BackendController extends AbstractController
{
    public function __construct(
        private readonly BackendRouteResolver $routeResolver,
        private readonly BackendAccessGuard $accessGuard,
        private readonly NavigationBuilder $navigationBuilder,
        private readonly HttpErrorRenderer $httpError,
        private readonly CoreSettingsFormHandler $coreSettingsFormHandler,
        private readonly AdminFeatureAccessPolicy $adminAcl,
        private readonly AdminAclSettingsFormHandler $adminAclSettingsFormHandler,
        private readonly ExtensionSettingsFormHandler $extensionSettingsFormHandler,
        private readonly AdminViewContextProvider $adminViewContextProvider,
        private readonly BackendActionResponder $backendActionResponder,
        private readonly AdminLogBrowser $logBrowser,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly FormTokenValidator $formTokenValidator,
        private readonly UiAlertDispatcherInterface $alerts,
    ) {
    }

    #[Route('/admin', name: 'backend_admin_index', methods: ['GET', 'POST'])]
    public function adminIndex(Request $request): Response
    {
        return $this->handle($request, BackendArea::Admin);
    }

    #[Route('/admin/logs/{entryId}', name: 'backend_admin_log_detail', requirements: ['entryId' => '(?:[0-9a-fA-F]{24}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})'], methods: ['GET'])]
    public function logDetail(Request $request, string $entryId): Response
    {
        $access = $this->adminAccessResponse($request);

        if (null !== $access) {
            return $access;
        }
        if (!$this->adminAcl->isVisible('admin.logs', $this->actor())) {
            return $this->httpError->resolve(Response::HTTP_UNAUTHORIZED, $request, context: [
                'feature' => 'admin.logs',
                'required_state' => 'visible',
            ]);
        }

        $source = $request->query->get('source', 'message');
        $source = is_string($source) ? $source : 'message';
        if (in_array($source, ['audit', 'security_signal'], true) && !$this->adminAcl->isMutable('admin.logs', $this->actor())) {
            return $this->httpError->resolve(Response::HTTP_UNAUTHORIZED, $request, context: [
                'feature' => 'admin.logs',
                'required_state' => 'mutable',
                'source' => $source,
            ]);
        }

        $entry = $this->logBrowser->entry($source, $entryId);

        if (null === $entry) {
            return $this->httpError->notFound($request);
        }

        return $this->render('@backend/admin/log-detail.html.twig', [
            'area' => BackendArea::Admin,
            'navigation' => $this->navigation($request, BackendArea::Admin),
            'log_entry' => $entry,
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
            return $this->httpError->resolve(Response::HTTP_UNAUTHORIZED, $request, context: [
                'area' => $area->value,
                'access_decision' => $decision->toArray(),
            ]);
        }

        if (BackendArea::Admin === $area && $request->isMethod('POST') && $this->backendActionResponder->supports($request)) {
            return $this->backendActionResponder->respond($request, $this->getUser());
        }

        $result = $this->routeResolver->resolve($area, $path);
        $view = $result->view();

        if (null !== $view && !$this->viewAllows($view, $actor)) {
            return $this->httpError->resolve(Response::HTTP_UNAUTHORIZED, $request, context: [
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

        $templateVariables += $this->adminViewContextProvider->variables($request, $view);

        return $this->render($result->template(), $templateVariables, new Response(status: $result->statusCode()));
    }

    private function adminAccessResponse(Request $request): ?Response
    {
        $decision = $this->accessGuard->decide(BackendArea::Admin, $this->getUser());

        if ($decision->isGranted()) {
            return null;
        }

        return $this->httpError->resolve(Response::HTTP_UNAUTHORIZED, $request, context: [
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
        $feature = $view->accessFeature();

        if (is_string($feature) && !$this->adminAcl->isVisible($feature, $actor)) {
            return false;
        }

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
        $auditAction = null;
        $auditContext = [];

        if ($this->backendActionResponder->supports($request)) {
            return $this->backendActionResponder->respond($request, $this->getUser());
        }

        if (isset($context['settings_section']) && is_string($context['settings_section'])) {
            $expectedFormId = 'admin-settings-'.$context['settings_section'];
            $auditAction = 'settings.core.save';
            $auditContext = ['section' => $context['settings_section']];
            if ($response = $this->mutationDeniedResponse($request, $view)) {
                return $response;
            }
            $result = $this->formTokenValidator->isValid($expectedFormId, $formId, $token)
                ? $this->coreSettingsFormHandler->submit($context['settings_section'], $request->request->all(), $this->actor()->userUid(), $this->actor())
                : $this->invalidCsrfResult($request);
        } elseif ('backend-admin-settings-extensions' === $view->uid()) {
            $expectedFormId = 'admin-settings-extensions';
            $auditAction = 'settings.core.save';
            $auditContext = ['section' => 'extensions'];
            if ($response = $this->mutationDeniedResponse($request, $view)) {
                return $response;
            }
            $result = $this->formTokenValidator->isValid($expectedFormId, $formId, $token)
                ? $this->coreSettingsFormHandler->submit('extensions', $request->request->all(), $this->actor()->userUid(), $this->actor())
                : $this->invalidCsrfResult($request);
        } elseif ('backend-admin-settings-acl' === $view->uid()) {
            $expectedFormId = 'admin-settings-acl';
            $auditAction = 'settings.acl.save';
            $auditContext = ['section' => 'acl'];
            $result = $this->formTokenValidator->isValid($expectedFormId, $formId, $token)
                ? $this->adminAclSettingsFormHandler->submit($request->request->all(), $this->actor()->userUid())
                : $this->invalidCsrfResult($request);
        } elseif (isset($context['extension_name']) && is_string($context['extension_name'])) {
            $expectedFormId = 'extension-settings-'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($context['extension_name']));
            $auditAction = 'settings.extension.save';
            $auditContext = ['extension' => $context['extension_name']];
            if ($response = $this->mutationDeniedResponse($request, $view)) {
                return $response;
            }
            $result = $this->formTokenValidator->isValid($expectedFormId, $formId, $token)
                ? $this->extensionSettingsFormHandler->submit($context['extension_name'], $request->request->all(), $this->actor()->userUid())
                : $this->invalidCsrfResult($request);
        }

        if (!$result instanceof FormSubmissionResult) {
            return $this->httpError->resolve(Response::HTTP_METHOD_NOT_ALLOWED, $request, context: [
                'area' => $view->area()->value,
                'view' => $view->uid(),
            ]);
        }

        if ($result->isValid()) {
            if (is_string($auditAction)) {
                $this->auditFormSubmission($auditAction, $result, [
                    ...$auditContext,
                    'route' => $request->getPathInfo(),
                ]);
            }

            $this->alerts->addAlert(UiAlertTranslation::success('admin.settings.form.saved'), UiAlertDelivery::Direct);

            return $this->redirect($request->getPathInfo());
        }

        $request->attributes->set('_system_form_values', $result->values());
        $request->attributes->set('_system_form_errors', $result->errors());
        $this->alerts->addAlert(UiAlertTranslation::error('admin.settings.form.errors.save_failed'), UiAlertDelivery::Direct);

        return null;
    }

    private function mutationDeniedResponse(Request $request, BackendViewDefinition $view): ?Response
    {
        $feature = $view->accessFeature();

        if (!is_string($feature) || $this->adminAcl->isMutable($feature, $this->actor())) {
            return null;
        }

        return $this->httpError->resolve(Response::HTTP_UNAUTHORIZED, $request, context: [
            'area' => $view->area()->value,
            'view' => $view->uid(),
            'access_feature' => $feature,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function auditFormSubmission(string $action, FormSubmissionResult $result, array $context = []): void
    {
        $settingKeys = array_filter(
            array_keys($result->values()),
            static fn (string $key): bool => !str_starts_with($key, '_'),
        );
        sort($settingKeys);
        $auditContext = $result->value('_audit');

        try {
            $this->auditLogger->log($this->actor(), $action, [
                ...$context,
                ...(is_array($auditContext) ? $auditContext : []),
                'result_status' => 'success',
                'setting_keys' => $settingKeys,
            ]);
        } catch (Throwable) {
            return;
        }
    }

    private function invalidCsrfResult(Request $request): FormSubmissionResult
    {
        return new FormSubmissionResult($request->request->all(), [
            '__form' => [FormErrorKey::INVALID_CSRF],
        ]);
    }

    private function stringField(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return is_string($value) ? $value : '';
    }
}
