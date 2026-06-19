<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionManifestVariables;
use App\Core\Manifest\Manifest;
use PHPUnit\Framework\TestCase;

final class ExtensionManifestVariablesTest extends TestCase
{
    public function testItCreatesTypedExtensionManifestVariablesFromManifestValues(): void
    {
        $variables = (new ExtensionManifestVariables())->fromManifest('icon-captcha', new Manifest([
            'EXTENSION_SLUG' => 'icon-captcha',
            'EXTENSION_SOMEKEY' => 'hallo welt',
            'EXTENSION_ENABLED' => 'true',
            'EXTENSION_LIMIT' => '42',
            'EXTENSION_BIG' => '9223372036854775808123',
            'EXTENSION_RATIO' => '0.75',
            'EXTENSION_TAGS' => '[captcha, security]',
            'CUSTOM_KEY' => 'ignored',
        ]));

        self::assertSame('hallo welt', $variables['ext.icon_captcha.somekey']['value']);
        self::assertSame('string', $variables['ext.icon_captcha.somekey']['type']);
        self::assertTrue($variables['ext.icon_captcha.enabled']['value']);
        self::assertSame('boolean', $variables['ext.icon_captcha.enabled']['type']);
        self::assertSame(42, $variables['ext.icon_captcha.limit']['value']);
        self::assertSame('integer', $variables['ext.icon_captcha.limit']['type']);
        self::assertSame('9223372036854775808123', $variables['ext.icon_captcha.big']['value']);
        self::assertSame('bigint', $variables['ext.icon_captcha.big']['type']);
        self::assertSame(0.75, $variables['ext.icon_captcha.ratio']['value']);
        self::assertSame('float', $variables['ext.icon_captcha.ratio']['type']);
        self::assertSame(['captcha', 'security'], $variables['ext.icon_captcha.tags']['value']);
        self::assertSame('array', $variables['ext.icon_captcha.tags']['type']);
        self::assertArrayNotHasKey('ext.icon_captcha.custom_key', $variables);
    }
}
