<?php

declare(strict_types=1);

namespace App\View\Http;

use App\Content\Read\PublishedContentResolver;
use App\Content\Render\ContentFieldsetRenderer;
use App\Core\Access\AccessActor;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;
use Twig\Error\Error as TwigError;

final readonly class HttpErrorRenderer
{
    public function __construct(
        private Environment $twig,
        private PublishedContentResolver $contentResolver,
        private ContentFieldsetRenderer $fieldsetRenderer,
        private Security $security,
        private bool $debug = false,
    ) {
    }

    public function notFound(Request $request, ?Throwable $exception = null): Response
    {
        return $this->render(Response::HTTP_NOT_FOUND, $request, $exception);
    }

    public function unauthorized(Request $request, ?Throwable $exception = null): Response
    {
        return $this->render(Response::HTTP_UNAUTHORIZED, $request, $exception);
    }

    public function forbidden(Request $request, ?Throwable $exception = null): Response
    {
        return $this->render(Response::HTTP_FORBIDDEN, $request, $exception);
    }

    public function maintenance(Request $request, ?Throwable $exception = null): Response
    {
        return $this->render(Response::HTTP_SERVICE_UNAVAILABLE, $request, $exception);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(int $statusCode, Request $request, ?Throwable $exception = null, array $context = []): Response
    {
        $variables = $this->variables($statusCode, $request, $exception, $context);

        if (Response::HTTP_UNAUTHORIZED === $statusCode && !$this->isAuthenticated()) {
            return $this->renderTemplate('@frontend/user/login.html.twig', $variables + [
                'return_to' => $this->returnTo($request),
            ], $statusCode);
        }

        $renderFailure = null;
        $contentResponse = $this->renderSystemErrorContent($statusCode, $request, $variables, $renderFailure);
        if (null !== $contentResponse) {
            return $contentResponse;
        }

        if ($this->debug && null === $exception && null !== $renderFailure) {
            $variables = $this->variables($statusCode, $request, $renderFailure, $context);
        }

        $statusTemplate = sprintf('@frontend/error-pages/%d.html.twig', $statusCode);

        if ($this->twig->getLoader()->exists($statusTemplate)) {
            try {
                return $this->renderTemplate($statusTemplate, $variables, $statusCode);
            } catch (TwigError $error) {
                if ($this->debug && null === $exception) {
                    $variables = $this->variables($statusCode, $request, $error, $context);
                }
            }
        }

        return $this->renderTemplate('@frontend/error-pages/default.html.twig', $variables, $statusCode);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderSystemErrorContent(int $statusCode, Request $request, array $variables, ?Throwable &$renderFailure = null): ?Response
    {
        try {
            $result = $this->contentResolver->resolveByPath(
                sprintf('/system/error-pages/%d', $statusCode),
                AccessActor::anonymous(),
                $this->language($request),
                'default',
            );
            $view = $result->view();

            if (null === $view) {
                return null;
            }

            return $this->renderTemplate('@frontend/content/entity.html.twig', array_replace($variables, [
                'content_view' => $view,
                'content_fieldset' => $this->fieldsetRenderer->render($view),
                'content_injections' => [],
            ]), $statusCode);
        } catch (Throwable $error) {
            $renderFailure = $error;

            return null;
        }
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderTemplate(string $template, array $variables, int $statusCode): Response
    {
        return new Response($this->twig->render($template, $variables), $statusCode);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function variables(int $statusCode, Request $request, ?Throwable $exception, array $context): array
    {
        $statusText = Response::$statusTexts[$statusCode] ?? 'HTTP Error';
        $translationPrefix = sprintf('ui.error.%d', $statusCode);
        $debug = null;

        if ($this->debug && null !== $exception) {
            $debug = [
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ];
        }

        $httpError = array_filter([
            'status_code' => $statusCode,
            'status_text' => $statusText,
            'title_key' => $translationPrefix.'.title',
            'message_key' => $translationPrefix.'.message',
            'request_path' => $request->getPathInfo(),
            'debug' => $debug,
            'context' => $context,
        ], static fn (mixed $value): bool => null !== $value);

        return [
            'http_error' => $httpError,
            'status_code' => $statusCode,
            'status_text' => $statusText,
            'status_title' => $translationPrefix.'.title',
            'status_message' => $translationPrefix.'.message',
        ];
    }

    private function language(Request $request): string
    {
        $queryLanguage = $request->query->get('language');

        if (is_string($queryLanguage) && '' !== $queryLanguage) {
            return $queryLanguage;
        }

        return $request->getLocale();
    }

    private function returnTo(Request $request): ?string
    {
        $uri = $request->getRequestUri();

        return $this->isSafeLocalTarget($uri) ? $uri : null;
    }

    private function isSafeLocalTarget(string $target): bool
    {
        return '' !== $target
            && str_starts_with($target, '/')
            && !str_starts_with($target, '//')
            && !str_contains($target, '\\')
            && 1 !== preg_match('/[\x00-\x1F\x7F]/', $target);
    }

    private function isAuthenticated(): bool
    {
        return null !== $this->security->getUser();
    }
}
