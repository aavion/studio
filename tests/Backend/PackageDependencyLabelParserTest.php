<?php

declare(strict_types=1);

namespace App\Tests\Backend;

use App\Backend\PackageDependencyLabelParser;
use PHPUnit\Framework\TestCase;

final class PackageDependencyLabelParserTest extends TestCase
{
    public function testItParsesDependencyLabelsFromManifestJson(): void
    {
        $parser = new PackageDependencyLabelParser();

        self::assertSame([
            'system 0.2.4',
            'demo-module',
            'provider 1.0',
        ], $parser->parse('[["system","0.2.4"],"demo-module",["provider","1.0",{"ignored":true}]]'));
    }

    public function testItKeepsMalformedDependencyValuesVisible(): void
    {
        $parser = new PackageDependencyLabelParser();

        self::assertSame([], $parser->parse(null));
        self::assertSame([], $parser->parse('[]'));
        self::assertSame(['not-json'], $parser->parse('not-json'));
    }
}
