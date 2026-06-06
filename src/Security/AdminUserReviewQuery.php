<?php

declare(strict_types=1);

namespace App\Security;

use App\Backend\BackendListViewHelper;
use Symfony\Component\HttpFoundation\Request;

final readonly class AdminUserReviewQuery
{
    public function __construct(
        public string $filter,
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
            filter: self::reviewFilter($request),
            search: $listViews->queryString($request, 'q'),
            sort: $listViews->queryChoice($request, 'sort', ['requested_at', 'email', 'kind', 'status'], 'requested_at'),
            direction: $listViews->queryChoice($request, 'direction', ['asc', 'desc'], 'desc'),
            page: $listViews->page($request->query->get('page')),
            perPage: $listViews->perPage($request->query->get('per_page')),
        );
    }

    /**
     * @return array{filter: string, search: string, sort: string, direction: string, per_page: int|string, page: int}
     */
    public function filters(int $page): array
    {
        return [
            'filter' => $this->filter,
            'search' => $this->search,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'per_page' => $this->perPage,
            'page' => $page,
        ];
    }

    private static function reviewFilter(Request $request): string
    {
        $filter = $request->query->get('filter');

        return is_string($filter) && in_array($filter, ['all', 'registrations', 'invitations', 'disputes', 'expired'], true)
            ? $filter
            : 'all';
    }
}
