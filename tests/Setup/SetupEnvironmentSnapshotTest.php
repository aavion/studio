<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\SetupEnvironmentSnapshot;
use PHPUnit\Framework\TestCase;

final class SetupEnvironmentSnapshotTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/system-backend-setup-env-snapshot-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItReportsRestoreErrorsWithoutThrowing(): void
    {
        file_put_contents($this->root.'/.env.test.local', "APP_FOO=old\n");
        $snapshot = SetupEnvironmentSnapshot::capture($this->root, 'test');
        unlink($this->root.'/.env.test.local');
        mkdir($this->root.'/.env.test.local');

        $result = $snapshot->restore();

        self::assertSame([], $result['removed']);
        self::assertSame([], $result['restored']);
        self::assertSame('.env.test.local', $result['errors'][0]['file'] ?? null);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir() && !$fileInfo->isLink()) {
                rmdir($fileInfo->getPathname());

                continue;
            }

            unlink($fileInfo->getPathname());
        }

        rmdir($path);
    }
}
