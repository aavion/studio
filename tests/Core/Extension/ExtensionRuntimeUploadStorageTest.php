<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Core\Extension\ExtensionStorage;
use App\Core\Extension\ExtensionUploadStorage;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ExtensionRuntimeUploadStorageTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/upload-facade');
        $this->removeDirectory($this->projectDir.'/var/extensions/test/upload-facade');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/upload-facade');
        $this->removeDirectory($this->projectDir.'/var/extensions/test/upload-facade');
        ExtensionRuntime::reset();
    }

    public function testItStoresValidUploadsForTheCallingExtension(): void
    {
        $this->configureRuntime();
        $upload = $this->uploadedFile('hello.txt', 'Hello upload.', 'text/plain');
        $this->writeExtensionFile(<<<'PHP'
            <?php

            $metadata = extension_upload_store($upload, 'uploads/hello.txt', ['ttl_seconds' => 60]);

            return [$metadata, extension_storage_get('uploads/hello.txt')];
            PHP);

        [$metadata, $contents] = require $this->projectDir.'/extensions/upload-facade/extension.php';

        self::assertSame('Hello upload.', $contents);
        self::assertSame('uploads/hello.txt', $metadata['path']);
        self::assertSame('hello.txt', $metadata['original_name']);
        self::assertSame(strlen('Hello upload.'), $metadata['size']);
        self::assertSame('text/plain', $metadata['mime_type']);
        self::assertSame('txt', $metadata['extension']);
    }

    public function testItRejectsNonExtensionCallersAndInvalidUploads(): void
    {
        $this->configureRuntime();

        self::assertNull(ExtensionRuntime::uploadStore($this->uploadedFile('hello.txt', 'ok'), 'uploads/hello.txt'));

        $invalid = $this->uploadedFile('missing.txt', 'missing', 'text/plain', UPLOAD_ERR_NO_FILE);
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return extension_upload_store($invalid, 'uploads/missing.txt');
            PHP);

        self::assertNull(require $this->projectDir.'/extensions/upload-facade/extension.php');
    }

    public function testItRejectsTraversalOversizedAndBlockedUploads(): void
    {
        $this->configureRuntime();
        $safe = $this->uploadedFile('safe.txt', 'safe');
        $oversized = $this->uploadedFile('large.txt', 'large');
        $php = $this->uploadedFile('shell.php', '<?php echo "bad";', 'text/x-php');
        $zip = $this->uploadedFile('archive.zip', 'PK'.random_bytes(12), 'application/zip');
        $svg = $this->uploadedFile('vector.svg', '<svg><script>alert(1)</script></svg>', 'image/svg+xml');
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_upload_store($safe, '../escape.txt'),
                extension_upload_store($safe, '/absolute.txt'),
                extension_upload_store($oversized, 'uploads/large.txt', ['max_bytes' => 4]),
                extension_upload_store($php, 'uploads/shell.php'),
                extension_upload_store($zip, 'uploads/archive.zip'),
                extension_upload_store($svg, 'uploads/vector.svg'),
                extension_storage_list('uploads'),
            ];
            PHP);

        self::assertSame(
            [null, null, null, null, null, null, []],
            require $this->projectDir.'/extensions/upload-facade/extension.php',
        );
    }

    private function configureRuntime(): void
    {
        $storage = new ExtensionStorage($this->projectDir, 'test');
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            storage: $storage,
            uploads: new ExtensionUploadStorage($storage),
        ));
    }

    private function uploadedFile(
        string $originalName,
        string $contents,
        string $mimeType = 'text/plain',
        int $error = UPLOAD_ERR_OK,
    ): UploadedFile {
        $directory = $this->createTemporaryDirectory('extension-upload');
        $path = $directory.'/'.$originalName;
        file_put_contents($path, $contents);

        return new UploadedFile($path, $originalName, $mimeType, $error, true);
    }

    private function writeExtensionFile(string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/upload-facade/extension.php', $contents);
    }
}
