<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Package\PackageScope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PackageScopeTest extends TestCase
{
    public function testItParsesDotenvStyleScopeLists(): void
    {
        self::assertSame(
            [PackageScope::FrontendTheme, PackageScope::SystemTemplate, PackageScope::Module],
            PackageScope::fromManifestValue('[frontend-theme, system-template, module]'),
        );
    }

    public function testItParsesSingleScopeValues(): void
    {
        self::assertSame(
            [PackageScope::CaptchaProvider],
            PackageScope::fromManifestValue('captcha-provider'),
        );
    }

    public function testItRejectsUnknownScopes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid package scope "unknown".');

        PackageScope::fromManifestValue('[module, unknown]');
    }
}
