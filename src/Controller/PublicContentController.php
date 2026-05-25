<?php

declare(strict_types=1);

namespace App\Controller;

use App\Content\Read\PublishedContentResolver;
use App\Content\Read\PublishedContentResolveStatus;
use App\Content\Routing\ContentRedirectResolveStatus;
use App\Content\Routing\ContentRedirectResolver;
use App\Content\Routing\ContentRouteLocalization;
use App\Content\Routing\ContentRoutePath;
use App\Content\Routing\ContentRouteGuard;
use App\Core\Access\AccessActor;
use App\Core\Message\MessageException;
use App\View\Http\HttpErrorRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicContentController extends AbstractController
{
    public function __construct(
        private readonly PublishedContentResolver $contentResolver,
        private readonly ContentRedirectResolver $redirectResolver,
        private readonly ContentRouteLocalization $localization,
        private readonly ContentRouteGuard $routeGuard,
        private readonly HttpErrorRenderer $httpError,
    ) {
    }

    #[Route('/', name: 'content_home', methods: ['GET'], priority: -1024)]
    public function home(Request $request): Response
    {
        return $this->renderLocalizedContent('/', $request);
    }

    #[Route('/{path}', name: 'content_show', requirements: ['path' => '.+'], methods: ['GET'], priority: -1024)]
    public function show(string $path, Request $request): Response
    {
        return $this->renderLocalizedContent($path, $request);
    }

    private function renderLocalizedContent(string $path, Request $request): Response
    {
        $localized = $this->localization->resolve($path, $request->getLanguages());

        if ($localized->shouldRedirect()) {
            if ('/' !== $localized->contentPath()) {
                try {
                    $this->routeGuard->assertPathAllowed($localized->contentPath());
                } catch (MessageException $exception) {
                    return $this->httpError->forbidden($request, $exception);
                }
            }

            return new RedirectResponse($this->redirectUri($localized->redirectPath(), $request), Response::HTTP_FOUND);
        }

        $localizedPath = ContentRoutePath::fromPath($localized->contentPath());

        if ('/' === $localizedPath->path()) {
            $path = $this->pathWithVariant($this->localization->homePath(), $localizedPath->variant());
        } else {
            try {
                $path = $this->routeGuard->assertPathAllowed($localized->contentPath());
            } catch (MessageException $exception) {
                return $this->httpError->forbidden($request, $exception);
            }
        }

        $language = $this->localization->isEnabled()
            ? $localized->language()
            : $this->readLanguage($request, $localized->language());

        return $this->renderContent($path, $language, $request);
    }

    private function renderContent(string $path, string $language, Request $request): Response
    {
        $redirect = $this->redirectResolver->resolveByPath($path);

        if ($redirect->isResolved()) {
            if ($redirect->isExternalUrl()) {
                return new RedirectResponse((string) $redirect->redirectRoute(), Response::HTTP_FOUND);
            }

            return $this->renderContentRoute((string) $redirect->redirectRoute(), $language, $request);
        }

        if (in_array($redirect->status(), [
            ContentRedirectResolveStatus::InvalidTarget,
            ContentRedirectResolveStatus::LoopDetected,
            ContentRedirectResolveStatus::HopLimitExceeded,
        ], true)) {
            return $this->httpError->notFound($request);
        }

        return $this->renderContentRoute($path, $language, $request);
    }

    private function renderContentRoute(string $path, string $language, Request $request): Response
    {
        $routePath = ContentRoutePath::fromPath($path);

        if ('/' === $routePath->path()) {
            $path = $this->pathWithVariant($this->localization->homePath(), $routePath->variant());
            $routePath = ContentRoutePath::fromPath($path);
        }

        $result = $this->contentResolver->resolveByPath(
            $path,
            AccessActor::anonymous(),
            $language,
            $routePath->variant() ?? $this->readQueryString($request, 'variant', 'default'),
        );
        $view = $result->view();

        if (null !== $view) {
            return $this->render('@frontend/content/entity.html.twig', [
                'content_view' => $view,
            ]);
        }

        if (PublishedContentResolveStatus::ContextUnavailable === $result->status()) {
            return $this->httpError->notFound($request);
        }

        if ($result->isUnauthorized()) {
            return $this->httpError->unauthorized($request);
        }

        if ($result->isForbidden()) {
            return $this->httpError->forbidden($request);
        }

        return $this->httpError->notFound($request);
    }

    private function pathWithVariant(string $path, ?string $variant): string
    {
        if (null === $variant) {
            return $path;
        }

        return rtrim($path, '/').'/~'.$variant;
    }

    private function readQueryString(Request $request, string $name, string $default): string
    {
        $value = $request->query->get($name);

        return is_string($value) ? $value : $default;
    }

    private function readLanguage(Request $request, string $default): string
    {
        $locale = $request->attributes->get('_locale');

        if (is_string($locale) && '' !== $locale) {
            return $locale;
        }

        return $this->readQueryString($request, 'language', $default);
    }

    private function redirectUri(?string $path, Request $request): string
    {
        $uri = $path ?? '/';
        $query = $request->getQueryString();

        return is_string($query) && '' !== $query ? $uri.'?'.$query : $uri;
    }
}
