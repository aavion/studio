<?php

declare(strict_types=1);

namespace App\Security;

use App\Backend\BackendListViewHelper;
use Symfony\Component\HttpFoundation\Request;

final readonly class AdminUserListQuery
{
    public function __construct(
        public string $search,
        public string $status,
        public string $role,
        public string $group,
        public string $sort,
        public string $direction,
        public int $page,
        public int|string $perPage,
    ) {
    }

    public static function fromRequest(Request $request, BackendListViewHelper $listViews): self
    {
        return new self(
            search: $listViews->queryString($request, 'q'),
            status: $listViews->queryChoice($request, 'status', ['all', 'active', 'inactive'], 'all'),
            role: $listViews->queryChoice($request, 'role', ['all', ...array_map(static fn (UserRole $role): string => $role->value, UserRole::assignable())], 'all'),
            group: $listViews->queryString($request, 'group'),
            sort: $listViews->queryChoice($request, 'sort', ['username', 'email', 'status', 'role'], 'username'),
            direction: $listViews->queryChoice($request, 'direction', ['asc', 'desc'], 'asc'),
            page: $listViews->page($request->query->get('page')),
            perPage: $listViews->perPage($request->query->get('per_page')),
        );
    }

    /**
     * @return array{search: string, status: string, role: string, group: string, sort: string, direction: string, per_page: int|string, page: int}
     */
    public function filters(int $page): array
    {
        return [
            'search' => $this->search,
            'status' => $this->status,
            'role' => $this->role,
            'group' => $this->group,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'per_page' => $this->perPage,
            'page' => $page,
        ];
    }
}
