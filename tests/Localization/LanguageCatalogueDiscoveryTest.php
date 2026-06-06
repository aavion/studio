<?php

declare(strict_types=1);

namespace App\Tests\Localization;

use App\Core\Translation\TranslationRuntimePath;
use App\Localization\LanguageCatalogueDiscovery;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class LanguageCatalogueDiscoveryTest extends TestCase
{
    use FilesystemTestHelper;

    public function testItDiscoversLanguagesFromRuntimeAndSourceCatalogues(): void
    {
        $root = $this->createTemporaryDirectory('language-catalogue-discovery');
        mkdir($root.'/translations/runtime/test', 0775, true);
        mkdir($root.'/translations/languages/en_US', 0775, true);
        touch($root.'/translations/runtime/test/messages.de.yaml');
        touch($root.'/translations/runtime/test/messages.de.yaml.tmp');
        touch($root.'/translations/runtime/test/validators.fr.yaml');

        $discovery = new LanguageCatalogueDiscovery($root, new TranslationRuntimePath($root, 'test'));

        self::assertSame(['de', 'en_US'], $discovery->availableLanguages());
    }
}
