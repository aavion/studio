<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Security\AdminUserReviewViewFactory;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class UserReviewApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private AdminUserReviewViewFactory $reviews,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return UserApiEndpointProvider::HANDLER_USER_REVIEWS_INDEX;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        $view = $this->reviews->reviewView($request);
        $items = array_map($this->resource(...), $view['items']);
        unset($view['items']);

        return $this->responder->data($items, meta: $view);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function resource(array $item): array
    {
        $requestedAt = $item['requested_at'] ?? null;

        return [
            'type' => 'user_review',
            'id' => sha1((string) ($item['kind'] ?? '').'|'.(string) ($item['email'] ?? '').'|'.(string) ($item['status'] ?? '')),
            'attributes' => [
                'kind' => $item['kind'] ?? null,
                'filter' => $item['filter'] ?? null,
                'status' => $item['status'] ?? null,
                'expired' => (bool) ($item['expired'] ?? false),
                'email' => $item['email'] ?? null,
                'username' => $item['username'] ?? null,
                'requested_at' => $requestedAt instanceof DateTimeInterface ? $requestedAt->format(DATE_ATOM) : null,
                'role' => $item['role'] ?? null,
                'groups' => is_array($item['groups'] ?? null) ? $item['groups'] : [],
            ],
        ];
    }
}
