<?php

declare(strict_types=1);

namespace App\Tests\Localization;

use App\Localization\TranslationLanguageCatalog;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class TranslationLanguageCatalogTest extends TestCase
{
    use FilesystemTestHelper;

    public function testItDiscoversLanguagesFromTranslationCatalogues(): void
    {
        $root = $this->createTemporaryDirectory('translation-language-catalog');
        mkdir($root.'/translations', 0775, true);
        touch($root.'/translations/messages.en.yaml');
        touch($root.'/translations/messages.de.yaml');
        touch($root.'/translations/validators.en.yaml');

        $catalog = new TranslationLanguageCatalog($root);

        self::assertSame(['de', 'en'], $catalog->availableLanguages());
        self::assertSame('en', $catalog->defaultLanguage());
    }
}
