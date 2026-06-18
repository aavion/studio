<?php

declare(strict_types=1);

namespace App\View\Http;

use App\Content\Read\PublishedContentResolver;
use App\Content\Render\ContentFieldsetRenderer;
use App\Core\Access\AccessActor;
use App\Core\Log\AccessRequestMetadata;
use App\Setup\SetupCompletionMarker;
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
        private SetupCompletionMarker $setupCompletionMarker,
        private AccessRequestMetadata $requestMetadata,
        private string $projectDir,
        private string $environment,
        private bool $debug = false,
    ) {
    }

    public function notFound(Request $request, ?Throwable $exception = null): Response
    {
        return $this->resolve(Response::HTTP_NOT_FOUND, $request, exception: $exception);
    }

    public function unauthorized(Request $request, ?Throwable $exception = null): Response
    {
        return $this->resolve(Response::HTTP_UNAUTHORIZED, $request, exception: $exception);
    }

    public function forbidden(Request $request, ?Throwable $exception = null): Response
    {
        return $this->resolve(Response::HTTP_FORBIDDEN, $request, exception: $exception);
    }

    public function maintenance(Request $request, ?Throwable $exception = null): Response
    {
        return $this->resolve(Response::HTTP_SERVICE_UNAVAILABLE, $request, exception: $exception);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $headers
     */
    public function bare(int $statusCode, ?Request $request = null, array $context = [], array $headers = []): Response
    {
        return $this->bareResponse($statusCode, $request, $context, $headers);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function resolve(int $statusCode, Request $request, array $context = [], ?Throwable $exception = null, bool $forceBare = false): Response
    {
        if ($forceBare || $this->preSetupBareStatus($statusCode)) {
            return $this->bareResponse($statusCode, $request, $context);
        }

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
        $response = new Response($this->twig->render($template, $variables), $statusCode);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function preSetupBareStatus(int $statusCode): bool
    {
        return $this->knownErrorStatus($statusCode)
            && !$this->setupCompletionMarker->isComplete($this->projectDir, $this->environment);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $headers
     */
    private function bareResponse(int $statusCode, ?Request $request = null, array $context = [], array $headers = []): Response
    {
        return new Response($this->bareHtml($statusCode, $request, $context), $statusCode, [
            ...$headers,
            'Cache-Control' => 'no-store',
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function bareHtml(int $statusCode, ?Request $request, array $context): string
    {
        $statusText = Response::$statusTexts[$statusCode] ?? 'HTTP Error';
        $contextText = $this->bareContextText($context);
        $contextHtml = null === $contextText ? '' : "\n<p>".$this->escape($contextText).'</p>';

        return '<!doctype html>'
            ."\n".'<meta charset="utf-8">'
            ."\n".'<h1 style="color:darkblue;">'.$statusCode.' - '.$this->escape($statusText).'</h1>'
            .$contextHtml
            ."\n".'<pre><strong>Request-ID:</strong> '.$this->escape($this->bareRequestId($request, $context)).'</pre>';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function bareContextText(array $context): ?string
    {
        $value = $context['bare_context'] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return '' === $text ? null : substr($text, 0, 500);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function bareRequestId(?Request $request, array $context): string
    {
        if ($request instanceof Request) {
            return $this->requestMetadata->requestId($request);
        }

        $contextRequestId = $context['request_id'] ?? null;
        if (is_scalar($contextRequestId) && '' !== trim((string) $contextRequestId)) {
            return substr((string) $contextRequestId, 0, 64);
        }

        return 'n/a';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function knownErrorStatus(int $statusCode): bool
    {
        return $statusCode >= 400 && $statusCode < 600 && isset(Response::$statusTexts[$statusCode]);
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
