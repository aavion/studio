<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Core\Extension\ExtensionTranslationFacade;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExtensionRuntimeTranslationTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/trans-facade');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/trans-facade');
        ExtensionRuntime::reset();
    }

    public function testItTranslatesOnlyCallerOwnedExtensionKeys(): void
    {
        $translator = new RecordingExtensionTranslator();
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            translations: new ExtensionTranslationFacade($translator),
        ));
        self::assertSame('', ExtensionRuntime::trans('ext.trans-facade.runtime.ready'));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_trans('ext.trans-facade.runtime.ready', ['%name%' => 'Alice'], 'de'),
                extension_trans('ext.other.runtime.ready', ['%name%' => 'Mallory']),
                extension_trans('message.extension.discovery_completed'),
                extension_trans('ext.trans-facade.runtime.ready', ['%name%' => ['not portable']]),
            ];
            PHP);

        self::assertSame([
            'de|messages|ext.trans-facade.runtime.ready',
            '',
            '',
            'default|messages|ext.trans-facade.runtime.ready',
        ], require $this->projectDir.'/extensions/trans-facade/extension.php');
        self::assertSame([
            ['key' => 'ext.trans-facade.runtime.ready', 'parameters' => ['%name%' => 'Alice'], 'locale' => 'de'],
            ['key' => 'ext.trans-facade.runtime.ready', 'parameters' => [], 'locale' => null],
        ], $translator->calls);
    }

    private function writeExtensionFile(string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/trans-facade/extension.php', $contents);
    }
}

final class RecordingExtensionTranslator implements TranslatorInterface
{
    /**
     * @var list<array{key: string, parameters: array<string, string>, locale: string|null}>
     */
    public array $calls = [];

    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $this->calls[] = ['key' => $id, 'parameters' => $parameters, 'locale' => $locale];

        return sprintf('%s|%s|%s', $locale ?? 'default', $domain ?? 'default', strtr($id, $parameters));
    }

    public function getLocale(): string
    {
        return 'en';
    }
}
