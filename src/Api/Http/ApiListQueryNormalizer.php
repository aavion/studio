<?php

declare(strict_types=1);

namespace App\Api\Http;

use Symfony\Component\HttpFoundation\Request;

final readonly class ApiListQueryNormalizer
{
    public function backendRequest(Request $request): Request
    {
        $copy = $request->duplicate();
        $copy->query->replace($this->backendQuery($request->query->all()));

        return $copy;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function backendQuery(array $query): array
    {
        unset($query['per_page']);

        if (array_key_exists('limit', $query)) {
            $query['per_page'] = $query['limit'];
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    public function apiMeta(array $meta): array
    {
        if (isset($meta['filters']) && is_array($meta['filters'])) {
            $meta['filters'] = $this->filters($meta['filters']);
        }

        if (isset($meta['pagination']) && is_array($meta['pagination'])) {
            $meta['pagination'] = $this->pagination($meta['pagination']);
        }

        if (isset($meta['per_page_options']) && is_array($meta['per_page_options'])) {
            $meta['limit_options'] = $meta['per_page_options'];
        }

        unset($meta['per_page_options']);

        return $meta;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    private function filters(array $filters): array
    {
        if (array_key_exists('per_page', $filters) && !array_key_exists('limit', $filters)) {
            $filters['limit'] = $filters['per_page'];
        }

        unset($filters['per_page']);

        return $filters;
    }

    /**
     * @param array<string, mixed> $pagination
     *
     * @return array<string, mixed>
     */
    private function pagination(array $pagination): array
    {
        if (array_key_exists('per_page', $pagination) && !array_key_exists('limit', $pagination)) {
            $pagination['limit'] = $pagination['per_page'];
        }

        if (array_key_exists('total_pages', $pagination) && !array_key_exists('page_count', $pagination)) {
            $pagination['page_count'] = $pagination['total_pages'];
        }

        unset($pagination['per_page'], $pagination['total_pages']);

        return $pagination;
    }
}
