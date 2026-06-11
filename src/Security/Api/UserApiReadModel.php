<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\Http\ApiListQueryNormalizer;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use App\Security\AdminUserListViewFactory;
use Symfony\Component\HttpFoundation\Request;

final readonly class UserApiReadModel
{
    public function __construct(
        private AdminUserListViewFactory $users,
        private ApiListQueryNormalizer $listQueries,
    ) {
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function users(Request $request): array
    {
        $view = $this->users->usersView($this->listQueries->backendRequest($request));
        $meta = $this->listQueries->apiMeta([
            'filters' => $view['filters'],
            'pagination' => $view['pagination'],
        ]);

        return [
            'data' => array_map(
                fn (UserAccount $user): array => $this->resource($user),
                $view['items'],
            ),
            'meta' => $meta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resource(UserAccount $user, bool $includeUid = false): array
    {
        $resource = [
            'type' => 'user',
            'id' => $user->username(),
            'attributes' => [
                'username' => $user->username(),
                'email' => $user->email(),
                'status' => $user->status()->value,
                'role' => $user->role()->value,
                'access_level' => $user->accessLevel(),
                'groups' => $this->groups($user),
            ],
            'links' => [
                'self' => '/api/v1/admin/users/items/'.$user->username(),
            ],
        ];

        if ($includeUid) {
            $resource['attributes']['uid'] = $user->uid();
        }

        return $resource;
    }

    /**
     * @return list<array{identifier: string, name: string, min_role: int}>
     */
    private function groups(UserAccount $user): array
    {
        $groups = [];

        foreach ($user->groups() as $group) {
            if ($group instanceof AclGroup) {
                $groups[] = [
                    'identifier' => $group->identifier(),
                    'name' => $group->name(),
                    'min_role' => $group->minRole(),
                ];
            }
        }

        usort($groups, static fn (array $left, array $right): int => $left['identifier'] <=> $right['identifier']);

        return $groups;
    }
}
