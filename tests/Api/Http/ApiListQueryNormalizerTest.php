<?php

declare(strict_types=1);

namespace App\Tests\Api\Http;

use App\Api\Http\ApiListQueryNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ApiListQueryNormalizerTest extends TestCase
{
    public function testItMapsApiLimitToBackendPerPageWithoutAcceptingApiPerPage(): void
    {
        $normalizer = new ApiListQueryNormalizer();
        $request = Request::create('/api/v1/admin/users?limit=50&per_page=all&page=2');

        $backend = $normalizer->backendRequest($request);

        self::assertSame('50', $backend->query->get('per_page'));
        self::assertSame('50', $backend->query->get('limit'));
        self::assertSame('all', $request->query->get('per_page'));
    }

    public function testItMapsBackendListMetaToApiTerms(): void
    {
        $meta = (new ApiListQueryNormalizer())->apiMeta([
            'filters' => [
                'q' => 'needle',
                'per_page' => 25,
            ],
            'pagination' => [
                'page' => 2,
                'per_page' => 25,
                'total' => 51,
                'total_pages' => 3,
            ],
            'per_page_options' => [
                ['key' => 25, 'label' => '25'],
            ],
        ]);

        self::assertSame(25, $meta['filters']['limit']);
        self::assertArrayNotHasKey('per_page', $meta['filters']);
        self::assertSame(25, $meta['pagination']['limit']);
        self::assertSame(3, $meta['pagination']['page_count']);
        self::assertArrayNotHasKey('per_page', $meta['pagination']);
        self::assertArrayNotHasKey('total_pages', $meta['pagination']);
        self::assertSame([['key' => 25, 'label' => '25']], $meta['limit_options']);
        self::assertArrayNotHasKey('per_page_options', $meta);
    }
}
