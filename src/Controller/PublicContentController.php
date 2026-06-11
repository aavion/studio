<?php

declare(strict_types=1);

namespace App\Controller;

use App\Content\Event\ContentRenderContextEvent;
use App\Content\Event\ContentRenderedEvent;
use App\Content\Read\PublishedContentResolver;
use App\Content\Read\PublishedContentResolveStatus;
use App\Content\Render\ContentFieldsetRenderer;
use App\Content\Routing\ContentRedirectResolveStatus;
use App\Content\Routing\ContentRedirectResolver;
use App\Content\Routing\ContentRouteLocalization;
use App\Content\Routing\ContentRoutePath;
use App\Content\Routing\ContentRouteGuard;
use App\Core\Access\AccessActor;
use App\Core\Access\AccessRule;
use App\Core\Event\PublicEventDispatcher;
use App\Core\Message\MessageException;
use App\Entity\UserAccount;
use App\View\Http\HttpErrorRenderer;
use App\View\Injection\DynamicViewInjectionRenderer;
use App\View\Injection\DynamicViewInjectionSlot;
use App\View\Injection\ViewInjectionRegistry;
use App\View\Injection\ViewSurface;
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
        private readonly PublicEventDispatcher $eventDispatcher,
        private readonly ViewInjectionRegistry $viewInjectionRegistry,
        private readonly DynamicViewInjectionRenderer $dynamicInjectionRenderer,
        private readonly ContentFieldsetRenderer $fieldsetRenderer,
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
            $this->actor(),
            $language,
            $routePath->variant() ?? $this->readQueryString($request, 'variant', 'default'),
        );
        $view = $result->view();

        if (null !== $view) {
            $actor = $this->actor();
            $dynamicRoute = null === $routePath->variant() || !$view->context()->variantFallbackUsed()
                ? null
                : $this->viewInjectionRegistry->findDynamicRoute(ViewSurface::Public, $view, $routePath->variant());

            if (null !== $dynamicRoute) {
                $content = $this->dynamicInjectionRenderer->renderRoute($dynamicRoute, $view, $request, $actor);

                if (null !== $content) {
                    return new Response($content);
                }
            }

            $context = [
                'content_view' => $view,
                'content_fieldset' => $this->fieldsetRenderer->render($view),
                'content_injections' => [
                    DynamicViewInjectionSlot::BeforeContent->value => $this->dynamicInjectionRenderer->renderSlot(
                        $view,
                        $request,
                        DynamicViewInjectionSlot::BeforeContent,
                        $actor,
                    ),
                    DynamicViewInjectionSlot::AfterContent->value => $this->dynamicInjectionRenderer->renderSlot(
                        $view,
                        $request,
                        DynamicViewInjectionSlot::AfterContent,
                        $actor,
                    ),
                ],
            ];
            $event = new ContentRenderContextEvent($view, $request, $context);
            $dispatchResult = $this->eventDispatcher->dispatch($event, [
                'operation' => 'content_render',
                'path' => $path,
                'language' => $language,
            ]);
            $context = $event->context();

            if (!$dispatchResult->isSuccess()) {
                $context['hook_issues'] = array_map(
                    static fn ($issue): array => $issue->toArray(),
                    $dispatchResult->issues(),
                );
            }

            $content = $this->renderView('@frontend/content/entity.html.twig', $context);
            $renderedEvent = new ContentRenderedEvent($view, $request, $content);
            $renderedResult = $this->eventDispatcher->dispatch($renderedEvent, [
                'operation' => 'content_rendered',
                'path' => $path,
                'language' => $language,
            ]);

            if ($renderedResult->isSuccess()) {
                $content = $renderedEvent->content();
            }

            return new Response($content);
        }

        if ($result->isUnauthorized()) {
            return null === $this->actor()->userUid()
                ? $this->httpError->unauthorized($request)
                : $this->httpError->forbidden($request);
        }

        if ($result->isForbidden()) {
            return $this->httpError->forbidden($request);
        }

        if (!in_array($result->status(), [
            PublishedContentResolveStatus::NotFound,
            PublishedContentResolveStatus::ContextUnavailable,
        ], true)) {
            return $this->httpError->notFound($request);
        }

        $staticInjection = $this->viewInjectionRegistry->findStatic(ViewSurface::Public, $path);

        if (null !== $staticInjection) {
            $actor = $this->actor();
            $accessRule = null === $staticInjection->accessLevel() && [] === $staticInjection->accessGroups()
                ? AccessRule::from(0)
                : AccessRule::from($staticInjection->accessLevel(), $staticInjection->accessGroups());

            if (!$accessRule->allows($actor)) {
                return null === $actor->userUid()
                    ? $this->httpError->unauthorized($request)
                    : $this->httpError->forbidden($request);
            }

            return $this->render($staticInjection->template(), [
                'injection' => $staticInjection,
                'request' => $request,
            ]);
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

    private function actor(): AccessActor
    {
        $user = $this->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }

    private function redirectUri(?string $path, Request $request): string
    {
        $uri = $path ?? '/';
        $query = $request->getQueryString();

        return is_string($query) && '' !== $query ? $uri.'?'.$query : $uri;
    }
}
