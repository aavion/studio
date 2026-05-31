<?php

declare(strict_types=1);

namespace App\Tests\Localization;

use App\Localization\CoreTranslationBootstrapper;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class CoreTranslationBootstrapperTest extends TestCase
{
    use FilesystemTestHelper;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('studio-core-translations');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItGeneratesCoreMessagesCataloguesWithoutPackageLookup(): void
    {
        $this->writeTestFile($this->root, 'translations/languages/en/message.yaml', "message:\n  setup:\n    ok: Ready\n");
        $this->writeTestFile($this->root, 'translations/languages/en/ui.yaml', "ui:\n  app:\n    name: Studio\n");
        $this->writeTestFile($this->root, 'translations/languages/de/message.yaml', "message:\n  setup:\n    ok: Bereit\n");

        $result = (new CoreTranslationBootstrapper())->generate($this->root, 'test');

        self::assertTrue($result['success']);
        self::assertSame(['de', 'en'], $result['locales']);
        self::assertSame(3, $result['files']);
        self::assertFileExists($this->root.'/translations/runtime/test/messages.en.yaml');

        $english = Yaml::parseFile($this->root.'/translations/runtime/test/messages.en.yaml');
        self::assertSame('Ready', $english['message']['setup']['ok']);
        self::assertSame('Studio', $english['ui']['app']['name']);
    }

    public function testItRejectsCoreTranslationKeyCollisions(): void
    {
        $this->writeTestFile($this->root, 'translations/languages/en/admin.yaml', "ui:\n  app:\n    name: Admin\n");
        $this->writeTestFile($this->root, 'translations/languages/en/ui.yaml', "ui:\n  app:\n    name: Studio\n");

        $result = (new CoreTranslationBootstrapper())->generate($this->root, 'test');

        self::assertFalse($result['success']);
        self::assertStringContainsString('Translation key "ui.app.name" is defined more than once', $result['error'] ?? '');
    }
}
