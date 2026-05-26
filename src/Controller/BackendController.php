<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\BackendAccessGuard;
use App\Backend\BackendArea;
use App\Backend\BackendRouteResolver;
use App\Backend\BackendViewDefinition;
use App\Core\Access\AccessActor;
use App\Entity\UserAccount;
use App\Navigation\NavigationBuilder;
use App\View\Http\HttpErrorRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BackendController extends AbstractController
{
    public function __construct(
        private readonly BackendRouteResolver $routeResolver,
        private readonly BackendAccessGuard $accessGuard,
        private readonly NavigationBuilder $navigationBuilder,
        private readonly HttpErrorRenderer $httpError,
    ) {
    }

    #[Route('/setup', name: 'backend_setup_index', methods: ['GET'])]
    public function setupIndex(Request $request): Response
    {
        return $this->handle($request, BackendArea::Setup);
    }

    #[Route('/setup/{path}', name: 'backend_setup_route', requirements: ['path' => '.+'], methods: ['GET'])]
    public function setupRoute(Request $request, string $path): Response
    {
        return $this->handle($request, BackendArea::Setup, $path);
    }

    #[Route('/admin', name: 'backend_admin_index', methods: ['GET'])]
    public function adminIndex(Request $request): Response
    {
        return $this->handle($request, BackendArea::Admin);
    }

    #[Route('/admin/{path}', name: 'backend_admin_route', requirements: ['path' => '.+'], methods: ['GET'])]
    public function adminRoute(Request $request, string $path): Response
    {
        if ('settings' === trim($path, '/')) {
            return $this->redirectToRoute('backend_admin_route', ['path' => 'settings/global']);
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

        $result = $this->routeResolver->resolve($area, $path);
        $view = $result->view();

        if (null !== $view && !$this->viewAllows($view, $actor)) {
            return $this->httpError->render(Response::HTTP_UNAUTHORIZED, $request, context: [
                'area' => $area->value,
                'view' => $view->uid(),
            ]);
        }

        return $this->render($result->template(), [
            'area' => $result->area(),
            'view' => $result->view(),
            'message' => $result->message()?->toArray(),
            'navigation' => $this->navigation($request, $area),
        ], new Response(status: $result->statusCode()));
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
}
