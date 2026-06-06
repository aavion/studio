<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\SetupLanguageCatalog;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class SetupLanguageCatalogTest extends TestCase
{
    use FilesystemTestHelper;

    public function testItUsesSharedLanguageCatalogueDiscovery(): void
    {
        $root = $this->createTemporaryDirectory('setup-language-catalog');
        mkdir($root.'/translations/runtime/test', 0775, true);
        touch($root.'/translations/runtime/test/messages.de.yaml');

        self::assertSame(['de'], (new SetupLanguageCatalog())->availableLanguages($root, 'test'));
        self::assertSame('de', (new SetupLanguageCatalog())->defaultLanguage($root, 'test'));
    }
}
