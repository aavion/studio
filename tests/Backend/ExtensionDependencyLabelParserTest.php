<?php

declare(strict_types=1);

namespace App\Tests\Backend;

use App\Backend\ExtensionDependencyLabelParser;
use App\Core\Manifest\ManifestParser;
use PHPUnit\Framework\TestCase;

final class ExtensionDependencyLabelParserTest extends TestCase
{
    public function testItParsesDependencyLabelsFromManifestJson(): void
    {
        $parser = new ExtensionDependencyLabelParser();
        $systemVersion = $this->rootManifestVersion();

        self::assertSame([
            'system '.$systemVersion,
            'demo-module',
            'provider 1.0',
        ], $parser->parse(sprintf('[["system","%s"],"demo-module",["provider","1.0",{"ignored":true}]]', $systemVersion)));
    }

    public function testItKeepsMalformedDependencyValuesVisible(): void
    {
        $parser = new ExtensionDependencyLabelParser();

        self::assertSame([], $parser->parse(null));
        self::assertSame([], $parser->parse('[]'));
        self::assertSame(['not-json'], $parser->parse('not-json'));
    }

    private function rootManifestVersion(): string
    {
        $result = (new ManifestParser())->parse((string) file_get_contents(dirname(__DIR__, 2).'/.manifest'));

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));

        return (string) $result->value()->get('APP_VERSION');
    }
}
