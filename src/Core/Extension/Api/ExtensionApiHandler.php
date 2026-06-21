<?php

declare(strict_types=1);

namespace App\Core\Extension\Api;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Admin\LiveOperationApiResourceFactory;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Backend\ExtensionLifecycleAdmin;
use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Operation\Live\LiveOperationStarter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ExtensionApiHandler implements ApiEndpointHandlerInterface
{
    private const EXTENSION_LIFECYCLE_FEATURE = 'admin.extensions';

    public function __construct(
        private ExtensionApiReadModel $readModel,
        private ExtensionLifecycleAdmin $lifecycleAdmin,
        private LiveOperationStarter $starter,
        private LiveOperationApiResourceFactory $operationResources,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
        private AdminFeatureAccessPolicy $adminAcl,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return ExtensionApiEndpointProvider::HANDLER_EXTENSIONS_INDEX;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        $actor = ApiRequestContext::fromRequest($request)?->actor() ?? AccessActor::anonymous();
        if (!$this->adminAcl->isVisible(self::EXTENSION_LIFECYCLE_FEATURE, $actor)) {
            return $this->responder->error(
                Message::warning(ApiMessageCode::API_OPERATION_UNAVAILABLE, ApiMessageKey::API_OPERATION_UNAVAILABLE, [
                    '%operation%' => 'extensionsRead',
                ], [
                    'feature' => self::EXTENSION_LIFECYCLE_FEATURE,
                    'reason' => 'feature_hidden',
                ]),
                Response::HTTP_FORBIDDEN,
                $request,
            );
        }

        $extensionSlug = $this->extensionSlugFromPath($request->getPathInfo());
        $extensionName = null === $extensionSlug ? null : $this->readModel->extensionNameForSlug($extensionSlug);
        if (null !== $extensionSlug && null === $extensionName) {
            return $this->notFound($request, $extensionSlug);
        }

        $action = $this->lifecycleActionFromPath($request->getPathInfo());
        if (null !== $extensionName && null !== $action) {
            return $this->lifecycle($request, $extensionName, $action);
        }

        if (null !== $extensionName) {
            return $this->extension($request, $extensionName);
        }

        $extensions = $this->readModel->extensions();

        return $this->responder->data($extensions, meta: [
            'count' => count($extensions),
        ]);
    }

    private function extension(Request $request, string $extensionName): Response
    {
        $extension = $this->lifecycleAdmin->extension($extensionName);
        if (null === $extension) {
            return $this->notFound($request, $extensionName);
        }

        return $this->responder->data($this->extensionResource($request, $extension));
    }

    private function lifecycle(Request $request, string $extensionName, string $action): Response
    {
        $actor = ApiRequestContext::fromRequest($request)?->actor() ?? AccessActor::anonymous();
        $state = $this->adminAcl->state(self::EXTENSION_LIFECYCLE_FEATURE, $actor);

        if (!$state->isVisible()) {
            return $this->responder->error(
                Message::warning(ApiMessageCode::API_OPERATION_UNAVAILABLE, ApiMessageKey::API_OPERATION_UNAVAILABLE, [
                    '%operation%' => 'extension'.str_replace(' ', '', ucwords(str_replace('-', ' ', $action))),
                ], [
                    'extension' => $extensionName,
                    'action' => $action,
                    'feature' => self::EXTENSION_LIFECYCLE_FEATURE,
                    'reason' => 'feature_hidden',
                ]),
                Response::HTTP_FORBIDDEN,
                $request,
            );
        }

        $review = $this->lifecycleAdmin->review($extensionName, $action);
        if (null === $review['extension']) {
            return $this->notFound($request, $extensionName);
        }

        if (!$request->query->getBoolean('confirm')) {
            return $this->responder->data([
                'type' => 'extension_lifecycle_review',
                'id' => $extensionName.':'.$action,
                'attributes' => [
                    ...$review,
                    'status' => $this->reviewStatus($review),
                    'confirm_parameter' => 'confirm=true',
                ],
            ]);
        }

        if (!$state->isMutable()) {
            return $this->responder->error(
                Message::warning(ApiMessageCode::API_OPERATION_UNAVAILABLE, ApiMessageKey::API_OPERATION_UNAVAILABLE, [
                    '%operation%' => 'extension'.str_replace(' ', '', ucwords(str_replace('-', ' ', $action))),
                ], [
                    'extension' => $extensionName,
                    'action' => $action,
                    'feature' => self::EXTENSION_LIFECYCLE_FEATURE,
                    'reason' => 'feature_read_only',
                ]),
                Response::HTTP_FORBIDDEN,
                $request,
            );
        }

        if (!$this->reviewAllowsConfirmation($review)) {
            return $this->operationUnavailable($request, 'extension'.str_replace(' ', '', ucwords(str_replace('-', ' ', $action))), [
                'extension' => $extensionName,
                'action' => $action,
                'reason' => 'review_not_successful',
                'review' => $review['plan'],
            ]);
        }

        $result = $this->starter->startTranslated(
            LiveOperationQueueFactory::EXTENSION_LIFECYCLE,
            ['extension' => $extensionName, 'action' => $action, 'trigger' => 'admin_api'],
            'admin.extensions.lifecycle.live_label',
            ['%extension%' => $extensionName, '%action%' => $action],
        );
        if (!$result->isSuccess() || !is_array($result->value())) {
            return $this->responder->error(
                $result->firstIssue() ?? Message::error(CommonMessageCode::E_OPERATION_FAILED, ApiMessageKey::API_OPERATION_UNAVAILABLE, [
                    '%operation%' => LiveOperationQueueFactory::EXTENSION_LIFECYCLE,
                ], [
                    'extension' => $extensionName,
                    'action' => $action,
                ]),
                Response::HTTP_BAD_REQUEST,
                $request,
            );
        }

        $resource = $this->operationResources->started($result->value());

        return $this->responder->data($resource, Response::HTTP_ACCEPTED, links: $resource['links'] ?? []);
    }

    /**
     * @param array<string, mixed> $extension
     *
     * @return array<string, mixed>
     */
    private function extensionResource(Request $request, array $extension): array
    {
        $extensionName = (string) $extension['extension_name'];
        $extensionSlug = $this->readModel->extensionSlug($extensionName);
        $actor = ApiRequestContext::fromRequest($request)?->actor() ?? AccessActor::anonymous();

        return [
            'type' => 'extension',
            'id' => $extensionSlug,
            'attributes' => [
                ...$extension,
                'extension_slug' => $extensionSlug,
                'api_path' => '/api/v1/admin/extensions/'.$extensionSlug,
                'api_actions' => $this->apiActionsForExtension($extension, $extensionSlug, $actor),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $extension
     *
     * @return list<array<string, mixed>>
     */
    private function apiActionsForExtension(array $extension, string $extensionSlug, AccessActor $actor): array
    {
        $state = $this->adminAcl->state(self::EXTENSION_LIFECYCLE_FEATURE, $actor);

        if (!$state->isVisible() || true === ($extension['immutable'] ?? false)) {
            return [];
        }

        $status = (string) ($extension['status'] ?? '');
        $actions = match ($status) {
            'inactive' => ['activate', 'delete'],
            'active' => ['deactivate', 'delete'],
            'faulty' => ['reset-fault', 'delete'],
            'removed' => ['purge'],
            default => [],
        };

        $resources = [];
        foreach ($actions as $action) {
            $resources[] = [
                'id' => $action,
                'method' => Request::METHOD_POST,
                'api_path' => '/api/v1/admin/extensions/'.$extensionSlug.'/'.$action,
                'requires_confirmation' => true,
                'disabled' => !$state->isMutable(),
            ];
        }

        return $resources;
    }

    /**
     * @param array<string, mixed> $review
     */
    private function reviewStatus(array $review): string
    {
        $plan = $review['plan'] ?? null;
        if (!is_array($plan)) {
            return 'fail';
        }

        if (true === ($plan['success'] ?? false)) {
            return [] === ($plan['issues'] ?? []) ? 'ok' : 'warn';
        }

        return 'fail';
    }

    /**
     * @param array<string, mixed> $review
     */
    private function reviewAllowsConfirmation(array $review): bool
    {
        $plan = $review['plan'] ?? null;

        return is_array($plan) && true === ($plan['success'] ?? false);
    }

    private function extensionSlugFromPath(string $path): ?string
    {
        if (1 !== preg_match('#^/api/v1/admin/extensions/([^/]+)(?:/(?:activate|deactivate|reset-fault|delete|purge))?$#', $path, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function lifecycleActionFromPath(string $path): ?string
    {
        if (1 !== preg_match('#^/api/v1/admin/extensions/[^/]+/(activate|deactivate|reset-fault|delete|purge)$#', $path, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function notFound(Request $request, string $extensionName): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_ENDPOINT_NOT_FOUND, ApiMessageKey::API_ENDPOINT_NOT_FOUND, context: [
                'path' => $request->getPathInfo(),
                'extension' => $extensionName,
            ]),
            Response::HTTP_NOT_FOUND,
            $request,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function operationUnavailable(Request $request, string $operation, array $context): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_OPERATION_UNAVAILABLE, ApiMessageKey::API_OPERATION_UNAVAILABLE, [
                '%operation%' => $operation,
            ], $context),
            Response::HTTP_CONFLICT,
            $request,
        );
    }
}
