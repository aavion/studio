<?php

declare(strict_types=1);

namespace App\Tests\Localization;

use App\Core\Translation\TranslationRuntimePath;
use App\Localization\TranslationLanguageCatalog;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class TranslationLanguageCatalogTest extends TestCase
{
    use FilesystemTestHelper;

    public function testItDiscoversLanguagesFromTranslationCatalogues(): void
    {
        $root = $this->createTemporaryDirectory('translation-language-catalog');
        mkdir($root.'/translations/runtime/test', 0775, true);
        touch($root.'/translations/runtime/test/messages.en.yaml');
        touch($root.'/translations/runtime/test/messages.de.yaml');
        touch($root.'/translations/validators.en.yaml');

        $catalog = new TranslationLanguageCatalog($root, new TranslationRuntimePath($root, 'test'));

        self::assertSame(['de', 'en'], $catalog->availableLanguages());
        self::assertSame('en', $catalog->defaultLanguage());
    }
}
