<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Entity\AclGroup;
use App\Security\AclGroupImpactService;

final readonly class UserGroupApiReadModel
{
    public function __construct(private AclGroupImpactService $impact)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function resource(AclGroup $group, bool $includeDetail = false): array
    {
        $resource = [
            'type' => 'acl_group',
            'id' => $group->identifier(),
            'attributes' => [
                'identifier' => $group->identifier(),
                'name' => $group->name(),
                'min_role' => $group->minRole(),
            ],
            'links' => [
                'self' => '/api/v1/admin/users/groups/items/'.$group->identifier(),
            ],
        ];

        if ($includeDetail) {
            $resource['attributes']['uid'] = $group->uid();
            $resource['relationships'] = [
                'impact' => $this->impact->impact($group),
            ];
        }

        return $resource;
    }

    /**
     * @param array<string, mixed>      $impact
     * @param array<string, mixed>|null $pending
     *
     * @return array<string, mixed>
     */
    public function review(AclGroup $group, string $operation, array $impact, ?array $pending = null): array
    {
        return [
            'type' => 'acl_group_review',
            'id' => $group->identifier(),
            'attributes' => [
                'status' => 'requires_confirmation',
                'operation' => $operation,
                'group' => $this->resource($group),
                'impact' => $impact,
                'pending' => $pending,
                'confirm_parameter' => 'confirm=true',
            ],
            'links' => $this->reviewLinks($group),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function reviewLinks(AclGroup $group): array
    {
        return [
            'self' => '/api/v1/admin/users/groups/items/'.$group->identifier(),
            'confirm' => '/api/v1/admin/users/groups/items/'.$group->identifier().'?confirm=true',
        ];
    }
}
