<?php

declare(strict_types=1);

namespace App\Content\Api;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Content\Read\PublishedContentResolver;
use App\Content\Read\PublishedContentResolveStatus;
use App\Core\Access\AccessActor;
use App\Core\Message\Message;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ContentApiItemHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private ContentApiItemReadModel $readModel,
        private PublishedContentResolver $contentResolver,
        private ContentApiPath $paths,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return ContentApiEndpointProvider::HANDLER_CONTENT_ITEMS;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $actor = ApiRequestContext::fromRequest($request)?->actor() ?? AccessActor::anonymous();
        $path = $request->getPathInfo();

        if (ContentApiPath::BASE === $path) {
            $items = $this->readModel->visibleItems($actor);

            return $this->responder->data($items, meta: ['count' => count($items)]);
        }

        $itemPath = $this->paths->itemFromRequestPath($path);
        if ($itemPath instanceof ContentApiItemPath) {
            return $this->read($request, $itemPath, $actor);
        }

        if (str_ends_with($path, '/items')) {
            return $this->children($request, $actor);
        }

        if (str_ends_with($path, '/variants')) {
            return $this->variants($request, $actor);
        }

        if (str_ends_with($path, '/revisions')) {
            return $this->revisions($request, $actor);
        }

        if (1 === preg_match('#/revisions/[1-9][0-9]*$#', $path)) {
            return $this->notImplemented($request, 'getContentItemRevision');
        }

        return $this->responder->data([], meta: ['count' => 0]);
    }

    private function read(Request $request, ContentApiItemPath $path, AccessActor $actor): Response
    {
        if (is_string($request->query->get('version'))) {
            return $this->notImplemented($request, 'readContentVersion');
        }

        $result = $this->contentResolver->resolveByPath(
            $path->contentPath(),
            $actor,
            $this->requestedLanguage($request),
            $path->variant() ?? $this->queryString($request, 'variant', 'default'),
        );
        $view = $result->view();

        if (null !== $view) {
            return $this->responder->data($this->readModel->viewResource($view), meta: [
                'messages' => $this->responder->messages($result->messages(), $request),
            ]);
        }

        return $this->resolveError($request, $result->status());
    }

    private function children(Request $request, AccessActor $actor): Response
    {
        $path = $this->paths->parentFromCollectionPath($request->getPathInfo(), 'items');
        $items = null === $path ? [] : $this->readModel->visibleChildren($path, $actor);

        return $this->responder->data($items, meta: ['count' => count($items)]);
    }

    private function variants(Request $request, AccessActor $actor): Response
    {
        $path = $this->paths->parentFromCollectionPath($request->getPathInfo(), 'variants');
        $variants = null === $path ? [] : $this->readModel->variants($path, $actor);

        return $this->responder->data($variants, meta: ['count' => count($variants)]);
    }

    private function revisions(Request $request, AccessActor $actor): Response
    {
        $path = $this->paths->parentFromCollectionPath($request->getPathInfo(), 'revisions');
        $versions = null === $path ? [] : $this->readModel->versions($path, $actor);

        return $this->responder->data($versions, meta: ['count' => count($versions)]);
    }

    private function resolveError(Request $request, PublishedContentResolveStatus $status): Response
    {
        if (PublishedContentResolveStatus::Denied === $status) {
            return $this->responder->error($this->notFoundMessage(), Response::HTTP_UNAUTHORIZED, $request);
        }

        if (PublishedContentResolveStatus::NotPublic === $status) {
            return $this->responder->error($this->notFoundMessage(), Response::HTTP_FORBIDDEN, $request);
        }

        return $this->responder->error($this->notFoundMessage(), Response::HTTP_NOT_FOUND, $request);
    }

    private function notImplemented(Request $request, string $operation): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_OPERATION_NOT_IMPLEMENTED, ApiMessageKey::API_OPERATION_NOT_IMPLEMENTED, [
                '%operation%' => $operation,
            ], ['operation' => $operation]),
            Response::HTTP_NOT_IMPLEMENTED,
            $request,
        );
    }

    private function notFoundMessage(): Message
    {
        return Message::warning(ApiMessageCode::API_ENDPOINT_NOT_FOUND, ApiMessageKey::API_ENDPOINT_NOT_FOUND);
    }

    private function queryString(Request $request, string $key, string $default): string
    {
        $value = $request->query->get($key);

        return is_string($value) ? $value : $default;
    }

    private function requestedLanguage(Request $request): string
    {
        $language = $this->queryString($request, 'language', '');
        if ('' !== $language) {
            return $language;
        }

        return $request->getLocale();
    }
}
