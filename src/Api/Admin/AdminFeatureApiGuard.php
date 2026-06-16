<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Core\Access\AccessActor;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\Message\Message;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AdminFeatureApiGuard
{
    public function __construct(
        private AdminFeatureAccessPolicy $adminAcl,
        private ApiResponder $responder,
    ) {
    }

    public function denyUnlessVisible(Request $request, string $feature, string $operation): ?Response
    {
        if ($this->adminAcl->isVisible($feature, $this->actor($request))) {
            return null;
        }

        return $this->denied($request, $feature, $operation, 'feature_hidden');
    }

    public function denyUnlessMutable(Request $request, string $feature, string $operation): ?Response
    {
        if ($this->adminAcl->isMutable($feature, $this->actor($request))) {
            return null;
        }

        return $this->denied($request, $feature, $operation, 'feature_read_only');
    }

    public function isMutable(Request $request, string $feature): bool
    {
        return $this->adminAcl->isMutable($feature, $this->actor($request));
    }

    private function actor(Request $request): AccessActor
    {
        return ApiRequestContext::fromRequest($request)?->actor() ?? AccessActor::anonymous();
    }

    private function denied(Request $request, string $feature, string $operation, string $reason): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_OPERATION_UNAVAILABLE, ApiMessageKey::API_OPERATION_UNAVAILABLE, [
                '%operation%' => $operation,
            ], [
                'feature' => $feature,
                'reason' => $reason,
            ]),
            Response::HTTP_FORBIDDEN,
            $request,
        );
    }
}
