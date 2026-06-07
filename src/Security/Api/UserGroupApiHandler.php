<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Entity\AclGroup;
use App\Security\AdminUserListViewFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class UserGroupApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private AdminUserListViewFactory $lists,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return UserApiEndpointProvider::HANDLER_USER_GROUPS_INDEX;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        $view = $this->lists->groupsView($request);
        $groups = array_map(static fn (AclGroup $group): array => [
            'type' => 'acl_group',
            'id' => $group->identifier(),
            'attributes' => [
                'identifier' => $group->identifier(),
                'name' => $group->name(),
                'min_role' => $group->minRole(),
            ],
        ], $view['items']);

        unset($view['items']);

        return $this->responder->data($groups, meta: $view);
    }
}
