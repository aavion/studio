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

    public function testItRequiresAtLeastOneIdentityScope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Extension scope list must include module, a theme scope, or a provider scope.');

        ExtensionScope::fromManifestValue('[system-template, api, database, content-schema, scheduler-tasks, operations]');
    }

    public function testItTreatsModuleThemeAndProviderScopesAsIdentityScopes(): void
    {
        self::assertTrue(ExtensionScope::Module->isIdentity());
        self::assertTrue(ExtensionScope::FrontendTheme->isIdentity());
        self::assertTrue(ExtensionScope::BackendTheme->isIdentity());
        self::assertTrue(ExtensionScope::CaptchaProvider->isIdentity());
        self::assertTrue(ExtensionScope::EditorProvider->isIdentity());
        self::assertFalse(ExtensionScope::SystemTemplate->isIdentity());
        self::assertFalse(ExtensionScope::Api->isIdentity());
        self::assertFalse(ExtensionScope::Database->isIdentity());
        self::assertFalse(ExtensionScope::ContentSchema->isIdentity());
        self::assertFalse(ExtensionScope::SchedulerTasks->isIdentity());
        self::assertFalse(ExtensionScope::Operations->isIdentity());
    }

    public function testItKnowsProviderScopes(): void
    {
        self::assertTrue(ExtensionScope::CaptchaProvider->isProvider());
        self::assertTrue(ExtensionScope::EditorProvider->isProvider());
        self::assertFalse(ExtensionScope::Module->isProvider());
        self::assertFalse(ExtensionScope::FrontendTheme->isProvider());
    }

    public function testItKnowsThemeScopes(): void
    {
        self::assertTrue(ExtensionScope::FrontendTheme->isTheme());
        self::assertTrue(ExtensionScope::BackendTheme->isTheme());
        self::assertFalse(ExtensionScope::SystemTemplate->isTheme());
        self::assertFalse(ExtensionScope::Module->isTheme());
        self::assertFalse(ExtensionScope::CaptchaProvider->isTheme());
    }

    public function testItRejectsUnknownScopes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid extension scope "unknown".');

        ExtensionScope::fromManifestValue('[module, unknown]');
    }
}
