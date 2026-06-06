<?php

declare(strict_types=1);

namespace App\Security;

use App\Backend\BackendListViewHelper;
use Symfony\Component\HttpFoundation\Request;

final readonly class AdminGroupListQuery
{
    public function __construct(
        public string $search,
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
            sort: $listViews->queryChoice($request, 'sort', ['identifier', 'name', 'min_role'], 'min_role'),
            direction: $listViews->queryChoice($request, 'direction', ['asc', 'desc'], 'asc'),
            page: $listViews->page($request->query->get('page')),
            perPage: $listViews->perPage($request->query->get('per_page')),
        );
    }

    /**
     * @return array{search: string, sort: string, direction: string, per_page: int|string, page: int}
     */
    public function filters(int $page): array
    {
        return [
            'search' => $this->search,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'per_page' => $this->perPage,
            'page' => $page,
        ];
    }
}
