<?php

declare(strict_types=1);

namespace App\Backend;

use Symfony\Component\HttpFoundation\Request;

final class BackendListViewHelper
{
    public function queryString(Request $request, string $key): string
    {
        $value = $request->query->get($key);

        return is_string($value) ? mb_substr(trim($value), 0, 120) : '';
    }

    /**
     * @param list<string> $choices
     */
    public function queryChoice(Request $request, string $key, array $choices, string $default): string
    {
        $value = $request->query->get($key);

        return is_string($value) && in_array($value, $choices, true) ? $value : $default;
    }

    public function perPage(mixed $perPage): int|string
    {
        if ('all' === $perPage) {
            return 'all';
        }

        $perPage = is_numeric($perPage) ? (int) $perPage : 25;

        return in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
    }

    public function page(mixed $page): int
    {
        $page = is_numeric($page) ? (int) $page : 1;

        return max(1, $page);
    }

    /**
     * @param list<mixed> $items
     *
     * @return array{items: list<mixed>, page: int, per_page: int|string, total: int, total_pages: int, has_previous: bool, has_next: bool, previous_page: int, next_page: int}
     */
    public function pagination(array $items, int $page, int|string $perPage): array
    {
        $total = count($items);

        if ('all' === $perPage) {
            return [
                'items' => $items,
                'page' => 1,
                'per_page' => 'all',
                'total' => $total,
                'total_pages' => 1,
                'has_previous' => false,
                'has_next' => false,
                'previous_page' => 1,
                'next_page' => 1,
            ];
        }

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages,
            'previous_page' => max(1, $page - 1),
            'next_page' => min($totalPages, $page + 1),
        ];
    }

    /**
     * @return list<array{key: int|string, label: string}>
     */
    public function perPageOptions(string $allLabel): array
    {
        return [
            ['key' => 25, 'label' => '25'],
            ['key' => 50, 'label' => '50'],
            ['key' => 100, 'label' => '100'],
            ['key' => 'all', 'label' => $allLabel],
        ];
    }
}
