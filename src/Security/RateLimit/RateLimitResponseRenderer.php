<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use App\Api\Http\ApiResponder;
use App\Core\Log\AccessRequestMetadata;
use App\Core\Message\Message;
use App\Core\Routing\PathScopeMatcher;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use App\View\Http\HttpErrorRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RateLimitResponseRenderer
{
    private PathScopeMatcher $paths;

    public function __construct(
        private HttpErrorRenderer $httpError,
        private ApiResponder $apiResponder,
        private AccessRequestMetadata $requestMetadata,
        ?PathScopeMatcher $paths = null,
    ) {
        $this->paths = $paths ?? new PathScopeMatcher();
    }

    public function tooManyRequests(Request $request, RateLimitCheckResult $result): Response
    {
        $response = $this->jsonSurface($request)
            ? $this->apiResponse($request, Response::HTTP_TOO_MANY_REQUESTS)
            : $this->httpError->resolve(Response::HTTP_TOO_MANY_REQUESTS, $request, context: $this->context($request));

        if (null !== $result->retryAfterSeconds()) {
            $response->headers->set('Retry-After', (string) $result->retryAfterSeconds());
        }

        return $this->noStore($response);
    }

    public function suspiciousProbe(Request $request): Response
    {
        $response = $this->jsonSurface($request)
            ? $this->apiResponse($request, Response::HTTP_BAD_REQUEST)
            : $this->httpError->resolve(Response::HTTP_BAD_REQUEST, $request, context: $this->context($request));

        return $this->noStore($response);
    }

    public function bare(Request $request, int $status, ?int $retryAfterSeconds = null): Response
    {
        $headers = [];
        $context = $this->context($request);
        if (null !== $retryAfterSeconds) {
            $headers['Retry-After'] = (string) $retryAfterSeconds;
            $context['bare_context'] = 'retry-after: '.$retryAfterSeconds;
        }

        return $this->httpError->bare($status, $request, $context, $headers);
    }

    private function apiResponse(Request $request, int $status): Response
    {
        $message = Response::HTTP_TOO_MANY_REQUESTS === $status
            ? Message::warning(SecurityMessageCode::RATE_LIMIT_EXCEEDED, SecurityMessageKey::RATE_LIMIT_EXCEEDED)
            : Message::warning(SecurityMessageCode::RATE_LIMIT_REQUEST_REJECTED, SecurityMessageKey::RATE_LIMIT_REQUEST_REJECTED);

        return $this->apiResponder->error(
            $message,
            $status,
            $request,
            $this->context($request),
        );
    }

    /**
     * @return array{request_id: string}
     */
    private function context(Request $request): array
    {
        return ['request_id' => $this->requestMetadata->requestId($request)];
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function jsonSurface(Request $request): bool
    {
        return $this->paths->matchesAnyPrefix($request->getPathInfo(), '/api/v1', '/cron');
    }
}
