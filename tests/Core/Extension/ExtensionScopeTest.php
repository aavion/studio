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
            [
                ExtensionScope::Module,
                ExtensionScope::Api,
                ExtensionScope::Database,
                ExtensionScope::ContentSchema,
                ExtensionScope::SchedulerTasks,
                ExtensionScope::Operations,
            ],
            ExtensionScope::fromManifestValue('[module, api, database, content-schema, scheduler-tasks, operations]'),
        );
    }

    public function testItKnowsProviderScopes(): void
    {
        self::assertTrue(ExtensionScope::CaptchaProvider->isProvider());
        self::assertTrue(ExtensionScope::EditorProvider->isProvider());
        self::assertFalse(ExtensionScope::Module->isProvider());
        self::assertFalse(ExtensionScope::FrontendTheme->isProvider());
    }

    public function testItRejectsUnknownScopes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid extension scope "unknown".');

        ExtensionScope::fromManifestValue('[module, unknown]');
    }
}
