<?php

declare(strict_types=1);

namespace App\Controller;

use App\Content\Read\PublishedContentResolver;
use App\Content\Read\PublishedContentResolveStatus;
use App\Content\Routing\ContentRouteGuard;
use App\Core\Access\AccessActor;
use App\Core\Message\MessageException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PublicContentController extends AbstractController
{
    public function __construct(
        private readonly PublishedContentResolver $contentResolver,
        private readonly ContentRouteGuard $routeGuard,
    ) {
    }

    #[Route('/', name: 'content_home', methods: ['GET'], priority: -1024)]
    public function home(Request $request): Response
    {
        return $this->renderContent('/', $request);
    }

    #[Route('/{path}', name: 'content_show', requirements: ['path' => '.+'], methods: ['GET'], priority: -1024)]
    public function show(string $path, Request $request): Response
    {
        try {
            $path = $this->routeGuard->assertPathAllowed($path);
        } catch (MessageException $exception) {
            throw new NotFoundHttpException(previous: $exception);
        }

        return $this->renderContent($path, $request);
    }

    private function renderContent(string $path, Request $request): Response
    {
        $result = $this->contentResolver->resolveByPath(
            $path,
            AccessActor::anonymous(),
            $this->readLanguage($request),
            $this->readQueryString($request, 'variant', 'default'),
        );
        $view = $result->view();

        if (null !== $view) {
            return $this->render('content/public.html.twig', [
                'content_view' => $view,
            ]);
        }

        if (PublishedContentResolveStatus::ContextUnavailable === $result->status()) {
            throw new NotFoundHttpException();
        }

        if ($result->isForbidden()) {
            throw new AccessDeniedHttpException();
        }

        throw new NotFoundHttpException();
    }

    private function readLanguage(Request $request): string
    {
        $locale = $request->attributes->get('_locale');

        if (is_string($locale) && '' !== $locale) {
            return $locale;
        }

        return $this->readQueryString($request, 'language', 'en');
    }

    private function readQueryString(Request $request, string $name, string $default): string
    {
        $value = $request->query->get($name);

        return is_string($value) ? $value : $default;
    }
}
