<?php

declare(strict_types=1);

namespace App\Tests\Core\Manifest;

use App\Core\Manifest\Manifest;
use App\Core\Manifest\ManifestParser;
use PHPUnit\Framework\TestCase;

final class ManifestParserTest extends TestCase
{
    public function testItParsesKeyValuePairs(): void
    {
        $result = (new ManifestParser())->parse(<<<'MANIFEST'
            # Application metadata
            APP_VERSION=0.1.0
            APP_CHANNEL=dev
            APP_SOURCE="https://example.test/releases"
            APP_DATE='2026-05-22'

            MANIFEST);

        self::assertTrue($result->isSuccess());
        self::assertInstanceOf(Manifest::class, $result->value());
        self::assertSame('0.1.0', $result->value()->get('APP_VERSION'));
        self::assertSame('dev', $result->value()->get('APP_CHANNEL'));
        self::assertSame('https://example.test/releases', $result->value()->get('APP_SOURCE'));
        self::assertSame('2026-05-22', $result->value()->get('APP_DATE'));
    }

    public function testItKeepsEqualsSignsInsideValues(): void
    {
        $result = (new ManifestParser())->parse('APP_SOURCE=https://example.test/?a=b');

        self::assertTrue($result->isSuccess());
        self::assertSame('https://example.test/?a=b', $result->value()->get('APP_SOURCE'));
    }

    public function testItReturnsDefaultForMissingValues(): void
    {
        $result = (new ManifestParser())->parse('APP_VERSION=0.1.0');

        self::assertSame('fallback', $result->value()->get('APP_CHANNEL', 'fallback'));
        self::assertNull($result->value()->get('APP_CHANNEL'));
        self::assertFalse($result->value()->has('APP_CHANNEL'));
    }

    public function testItReportsInvalidLines(): void
    {
        $result = (new ManifestParser())->parse('APP_VERSION');

        self::assertFalse($result->isSuccess());
        self::assertSame('manifest.invalid_line', $result->firstIssue()?->code());
        self::assertSame(['line' => 1], $result->firstIssue()?->context());
    }

    public function testItReportsInvalidKeys(): void
    {
        $result = (new ManifestParser())->parse('app.version=0.1.0');

        self::assertFalse($result->isSuccess());
        self::assertSame('manifest.invalid_key', $result->firstIssue()?->code());
        self::assertSame(['line' => 1, 'key' => 'app.version'], $result->firstIssue()?->context());
    }

    public function testItReportsDuplicateKeys(): void
    {
        $result = (new ManifestParser())->parse(<<<'MANIFEST'
            APP_VERSION=0.1.0
            APP_VERSION=0.2.4
            MANIFEST);

        self::assertFalse($result->isSuccess());
        self::assertSame('manifest.duplicate_key', $result->firstIssue()?->code());
        self::assertSame(['line' => 2, 'key' => 'APP_VERSION'], $result->firstIssue()?->context());
    }
}
