<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionScope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExtensionScopeTest extends TestCase
{
    public function testItParsesDotenvStyleScopeLists(): void
    {
        self::assertSame(
            [ExtensionScope::FrontendTheme, ExtensionScope::SystemTemplate, ExtensionScope::Module],
            ExtensionScope::fromManifestValue('[frontend-theme, system-template, module]'),
        );
    }

    public function testItParsesSingleScopeValues(): void
    {
        self::assertSame(
            [ExtensionScope::CaptchaProvider],
            ExtensionScope::fromManifestValue('captcha-provider'),
        );
    }

    public function testItParsesDatabaseAndContentSchemaScopes(): void
    {
        self::assertSame(
            [ExtensionScope::Module, ExtensionScope::Database, ExtensionScope::ContentSchema],
            ExtensionScope::fromManifestValue('[module, database, content-schema]'),
        );
    }

    public function testItRejectsUnknownScopes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid extension scope "unknown".');

        ExtensionScope::fromManifestValue('[module, unknown]');
    }
}
