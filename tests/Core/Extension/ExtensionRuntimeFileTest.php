<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionFileReader;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class ExtensionRuntimeFileTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/file-facade');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/file-facade');
        ExtensionRuntime::reset();
    }

    public function testItReadsFilesOwnedByTheCallingExtension(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, files: new ExtensionFileReader($this->projectDir)));
        $this->writeTestFile($this->projectDir, 'extensions/file-facade/config/settings.json', '{"mode":"demo"}');
        $this->writeTestFile($this->projectDir, 'extensions/file-facade/private-assets/challenges/index.json', '{"ok":true}');
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_file_get('config/settings.json'),
                extension_file_get('private-assets/challenges/index.json'),
            ];
            PHP);

        self::assertSame(['{"mode":"demo"}', '{"ok":true}'], require $this->projectDir.'/extensions/file-facade/extension.php');
    }

    public function testItRejectsInvalidMissingOversizedAndNonExtensionReads(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, files: new ExtensionFileReader($this->projectDir)));
        self::assertNull(ExtensionRuntime::fileGet('config/settings.json'));

        $this->writeTestFile($this->projectDir, 'extensions/file-facade/config/settings.json', '{"mode":"demo"}');
        $this->writeTestFile($this->projectDir, 'extensions/file-facade/large.bin', str_repeat('x', ExtensionFileReader::MAX_BYTES + 1));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_file_get('../other-module/config.json'),
                extension_file_get('/etc/passwd'),
                extension_file_get('missing.txt'),
                extension_file_get('config'),
                extension_file_get('large.bin'),
            ];
            PHP);

        self::assertSame([null, null, null, null, null], require $this->projectDir.'/extensions/file-facade/extension.php');
    }

    public function testItRejectsSymlinkedExtensionFilePaths(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, files: new ExtensionFileReader($this->projectDir)));
        $this->writeTestFile($this->projectDir, 'extensions/file-facade/private-assets/secret.txt', 'secret');
        mkdir($this->projectDir.'/extensions/file-facade/config', 0777, true);
        $this->createSymlinkOrSkip(
            $this->projectDir.'/extensions/file-facade/private-assets',
            $this->projectDir.'/extensions/file-facade/config/linked',
        );
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return extension_file_get('config/linked/secret.txt');
            PHP);

        self::assertNull(require $this->projectDir.'/extensions/file-facade/extension.php');
    }

    private function writeExtensionFile(string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/file-facade/extension.php', $contents);
    }
}
