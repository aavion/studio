<?php

declare(strict_types=1);

namespace App\Core\Package\Api;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Admin\LiveOperationApiResourceFactory;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Backend\PackageLifecycleAdmin;
use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Operation\Live\LiveOperationStarter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class PackageApiHandler implements ApiEndpointHandlerInterface
{
    private const PACKAGE_LIFECYCLE_FEATURE = 'admin.packages';

    public function __construct(
        private PackageApiReadModel $readModel,
        private PackageLifecycleAdmin $lifecycleAdmin,
        private LiveOperationStarter $starter,
        private LiveOperationApiResourceFactory $operationResources,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
        private AdminFeatureAccessPolicy $adminAcl,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return PackageApiEndpointProvider::HANDLER_PACKAGES_INDEX;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        $actor = ApiRequestContext::fromRequest($request)?->actor() ?? AccessActor::anonymous();
        if (!$this->adminAcl->isVisible(self::PACKAGE_LIFECYCLE_FEATURE, $actor)) {
            return $this->responder->error(
                Message::warning(ApiMessageCode::API_OPERATION_UNAVAILABLE, ApiMessageKey::API_OPERATION_UNAVAILABLE, [
                    '%operation%' => 'packagesRead',
                ], [
                    'feature' => self::PACKAGE_LIFECYCLE_FEATURE,
                    'reason' => 'feature_hidden',
                ]),
                Response::HTTP_FORBIDDEN,
                $request,
            );
        }

        $packageSlug = $this->packageSlugFromPath($request->getPathInfo());
        $packageName = null === $packageSlug ? null : $this->readModel->packageNameForSlug($packageSlug);
        if (null !== $packageSlug && null === $packageName) {
            return $this->notFound($request, $packageSlug);
        }

        $action = $this->lifecycleActionFromPath($request->getPathInfo());
        if (null !== $packageName && null !== $action) {
            return $this->lifecycle($request, $packageName, $action);
        }

        if (null !== $packageName) {
            return $this->package($request, $packageName);
        }

        $packages = $this->readModel->packages();

        return $this->responder->data($packages, meta: [
            'count' => count($packages),
        ]);
    }

    private function package(Request $request, string $packageName): Response
    {
        $package = $this->lifecycleAdmin->package($packageName);
        if (null === $package) {
            return $this->notFound($request, $packageName);
        }

        return $this->responder->data($this->packageResource($request, $package));
    }

    private function lifecycle(Request $request, string $packageName, string $action): Response
    {
        $actor = ApiRequestContext::fromRequest($request)?->actor() ?? AccessActor::anonymous();
        $state = $this->adminAcl->state(self::PACKAGE_LIFECYCLE_FEATURE, $actor);

        if (!$state->isVisible()) {
            return $this->responder->error(
                Message::warning(ApiMessageCode::API_OPERATION_UNAVAILABLE, ApiMessageKey::API_OPERATION_UNAVAILABLE, [
                    '%operation%' => 'package'.str_replace(' ', '', ucwords(str_replace('-', ' ', $action))),
                ], [
                    'package' => $packageName,
                    'action' => $action,
                    'feature' => self::PACKAGE_LIFECYCLE_FEATURE,
                    'reason' => 'feature_hidden',
                ]),
                Response::HTTP_FORBIDDEN,
                $request,
            );
        }

        $review = $this->lifecycleAdmin->review($packageName, $action);
        if (null === $review['package']) {
            return $this->notFound($request, $packageName);
        }

        if (!$request->query->getBoolean('confirm')) {
            return $this->responder->data([
                'type' => 'package_lifecycle_review',
                'id' => $packageName.':'.$action,
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
                    '%operation%' => 'package'.str_replace(' ', '', ucwords(str_replace('-', ' ', $action))),
                ], [
                    'package' => $packageName,
                    'action' => $action,
                    'feature' => self::PACKAGE_LIFECYCLE_FEATURE,
                    'reason' => 'feature_read_only',
                ]),
                Response::HTTP_FORBIDDEN,
                $request,
            );
        }

        if (!$this->reviewAllowsConfirmation($review)) {
            return $this->operationUnavailable($request, 'package'.str_replace(' ', '', ucwords(str_replace('-', ' ', $action))), [
                'package' => $packageName,
                'action' => $action,
                'reason' => 'review_not_successful',
                'review' => $review['plan'],
            ]);
        }

        $result = $this->starter->start(
            LiveOperationQueueFactory::PACKAGE_LIFECYCLE,
            ['package' => $packageName, 'action' => $action, 'trigger' => 'admin_api'],
            sprintf('Package %s %s', $packageName, $action),
        );
        if (!$result->isSuccess() || !is_array($result->value())) {
            return $this->responder->error(
                $result->firstIssue() ?? Message::error(CommonMessageCode::E_OPERATION_FAILED, ApiMessageKey::API_OPERATION_UNAVAILABLE, [
                    '%operation%' => LiveOperationQueueFactory::PACKAGE_LIFECYCLE,
                ], [
                    'package' => $packageName,
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
     * @param array<string, mixed> $package
     *
     * @return array<string, mixed>
     */
    private function packageResource(Request $request, array $package): array
    {
        $packageName = (string) $package['package_name'];
        $packageSlug = $this->readModel->packageSlug($packageName);
        $actor = ApiRequestContext::fromRequest($request)?->actor() ?? AccessActor::anonymous();

        return [
            'type' => 'package',
            'id' => $packageSlug,
            'attributes' => [
                ...$package,
                'package_slug' => $packageSlug,
                'api_path' => '/api/v1/admin/packages/'.$packageSlug,
                'api_actions' => $this->apiActionsForPackage($package, $packageSlug, $actor),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return list<array<string, mixed>>
     */
    private function apiActionsForPackage(array $package, string $packageSlug, AccessActor $actor): array
    {
        $state = $this->adminAcl->state(self::PACKAGE_LIFECYCLE_FEATURE, $actor);

        if (!$state->isVisible() || true === ($package['immutable'] ?? false)) {
            return [];
        }

        $status = (string) ($package['status'] ?? '');
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
                'api_path' => '/api/v1/admin/packages/'.$packageSlug.'/'.$action,
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

    private function packageSlugFromPath(string $path): ?string
    {
        if (1 !== preg_match('#^/api/v1/admin/packages/([^/]+)(?:/(?:activate|deactivate|reset-fault|delete|purge))?$#', $path, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function lifecycleActionFromPath(string $path): ?string
    {
        if (1 !== preg_match('#^/api/v1/admin/packages/[^/]+/(activate|deactivate|reset-fault|delete|purge)$#', $path, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function notFound(Request $request, string $packageName): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_ENDPOINT_NOT_FOUND, ApiMessageKey::API_ENDPOINT_NOT_FOUND, context: [
                'path' => $request->getPathInfo(),
                'package' => $packageName,
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
