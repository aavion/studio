<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Core\Extension\ExtensionStorage;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class ExtensionRuntimeStorageTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/storage-facade-a');
        $this->removeDirectory($this->projectDir.'/extensions/storage-facade-b');
        $this->removeDirectory($this->projectDir.'/var/extensions/test/storage-facade-a');
        $this->removeDirectory($this->projectDir.'/var/extensions/test/storage-facade-b');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/storage-facade-a');
        $this->removeDirectory($this->projectDir.'/extensions/storage-facade-b');
        $this->removeDirectory($this->projectDir.'/var/extensions/test/storage-facade-a');
        $this->removeDirectory($this->projectDir.'/var/extensions/test/storage-facade-b');
        ExtensionRuntime::reset();
    }

    public function testItReadsWritesListsAndDeletesCallingExtensionStorage(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, storage: new ExtensionStorage($this->projectDir, 'test')));
        $this->writeExtensionFile('storage-facade-a', <<<'PHP'
            <?php

            $put = extension_storage_put('state/challenge.json', '{"ok":true}', ['ttl_seconds' => 60]);
            $existsBeforeDelete = extension_storage_exists('state/challenge.json');
            $contents = extension_storage_get('state/challenge.json');
            $list = extension_storage_list('state');
            $deleted = extension_storage_delete('state/challenge.json');
            $existsAfterDelete = extension_storage_exists('state/challenge.json');

            return [$put, $existsBeforeDelete, $contents, $list, $deleted, $existsAfterDelete];
            PHP);

        [$put, $existsBeforeDelete, $contents, $list, $deleted, $existsAfterDelete] = require $this->projectDir.'/extensions/storage-facade-a/extension.php';

        self::assertTrue($put);
        self::assertTrue($existsBeforeDelete);
        self::assertSame('{"ok":true}', $contents);
        self::assertCount(1, $list);
        self::assertSame('state/challenge.json', $list[0]['path']);
        self::assertSame(strlen('{"ok":true}'), $list[0]['size']);
        self::assertIsInt($list[0]['modified_at']);
        self::assertIsInt($list[0]['expires_at']);
        self::assertTrue($deleted);
        self::assertFalse($existsAfterDelete);
    }

    public function testItIsolatesStorageByCallingExtension(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, storage: new ExtensionStorage($this->projectDir, 'test')));
        $this->writeExtensionFile('storage-facade-a', <<<'PHP'
            <?php

            return extension_storage_put('shared/state.txt', 'a');
            PHP);
        $this->writeExtensionFile('storage-facade-b', <<<'PHP'
            <?php

            return extension_storage_get('shared/state.txt');
            PHP);

        self::assertTrue(require $this->projectDir.'/extensions/storage-facade-a/extension.php');
        self::assertNull(require $this->projectDir.'/extensions/storage-facade-b/extension.php');
    }

    public function testItRejectsInvalidPathsOversizedValuesAndNonExtensionCallers(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, storage: new ExtensionStorage($this->projectDir, 'test')));

        self::assertFalse(ExtensionRuntime::storagePut('state.txt', 'outside'));
        self::assertNull(ExtensionRuntime::storageGet('state.txt'));
        self::assertSame([], ExtensionRuntime::storageList());

        $this->writeExtensionFile('storage-facade-a', <<<'PHP'
            <?php

            return [
                extension_storage_put('../escape.txt', 'no'),
                extension_storage_put('/absolute.txt', 'no'),
                extension_storage_put('.metadata/private.json', 'no'),
                extension_storage_put('large.bin', str_repeat('x', 5242881)),
                extension_storage_put('ttl.txt', 'no', ['ttl_seconds' => 0]),
                extension_storage_get('../escape.txt'),
                extension_storage_list('.metadata'),
            ];
            PHP);

        self::assertSame(
            [false, false, false, false, false, null, []],
            require $this->projectDir.'/extensions/storage-facade-a/extension.php',
        );
    }

    public function testItRejectsSymlinkedStoragePaths(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, storage: new ExtensionStorage($this->projectDir, 'test')));
        $this->writeTestFile($this->projectDir, 'var/extensions/test/storage-facade-a/target/secret.txt', 'secret');
        mkdir($this->projectDir.'/var/extensions/test/storage-facade-a/storage', 0777, true);
        $this->createSymlinkOrSkip(
            $this->projectDir.'/var/extensions/test/storage-facade-a/target',
            $this->projectDir.'/var/extensions/test/storage-facade-a/storage/linked',
        );
        $this->writeExtensionFile('storage-facade-a', <<<'PHP'
            <?php

            return [
                extension_storage_get('linked/secret.txt'),
                extension_storage_put('linked/write.txt', 'no'),
            ];
            PHP);

        self::assertSame([null, false], require $this->projectDir.'/extensions/storage-facade-a/extension.php');
    }

    private function writeExtensionFile(string $extension, string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/'.$extension.'/extension.php', $contents);
    }
}
