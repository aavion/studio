<?php

declare(strict_types=1);

namespace App\Tests\Api\Admin;

use App\Api\Admin\LiveOperationApiResourceFactory;
use PHPUnit\Framework\TestCase;

final class LiveOperationApiResourceFactoryTest extends TestCase
{
    public function testStartedOperationResourcesExposeStatusLinks(): void
    {
        $resource = (new LiveOperationApiResourceFactory())->started([
            'operation_id' => '1234567890abcdef1234567890abcdef',
            'operation' => 'package.lifecycle',
            'label' => 'Package demo activate',
            'status' => 'queued',
        ]);

        self::assertSame('operation_start', $resource['type']);
        self::assertSame('/api/v1/admin/operations/1234567890abcdef1234567890abcdef', $resource['links']['status']);
        self::assertSame('/api/v1/admin/operations/1234567890abcdef1234567890abcdef', $resource['attributes']['status_path']);
    }

    public function testContinuationLinksExposeReviewAndConfirmTargets(): void
    {
        $links = (new LiveOperationApiResourceFactory())->continuationLinks('1234567890abcdef1234567890abcdef');

        self::assertSame('/api/v1/admin/operations/1234567890abcdef1234567890abcdef', $links['status']);
        self::assertSame('/api/v1/admin/operations/1234567890abcdef1234567890abcdef/continue', $links['continue']);
        self::assertSame('/api/v1/admin/operations/1234567890abcdef1234567890abcdef/continue?confirm=true', $links['confirm']);
    }
}
