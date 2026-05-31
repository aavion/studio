<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\SetupPreflightChecker;
use PHPUnit\Framework\TestCase;

final class SetupPreflightCheckerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/studio_preflight_'.bin2hex(random_bytes(6));
        mkdir($this->root.'/public', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItDetectsProjectRootWebroot(): void
    {
        $result = (new SetupPreflightChecker())->check($this->root, 'test', server: [
            'DOCUMENT_ROOT' => $this->root,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('failed', $result['checks'][0]['status']);
        self::assertSame('setup.preflight.checks.webroot_public.instruction', $result['checks'][0]['instruction_key']);
    }

    public function testItAutoHealsMissingWritablePaths(): void
    {
        $result = (new SetupPreflightChecker())->check($this->root, 'test', autoHeal: true, server: [
            'DOCUMENT_ROOT' => $this->root.'/public',
        ]);

        self::assertTrue($result['ok']);
        self::assertFileExists($this->root.'/.env.test.local');
        self::assertDirectoryExists($this->root.'/var');
        self::assertDirectoryExists($this->root.'/translations/runtime');
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = array_diff(scandir($path) ?: [], ['.', '..']);

        foreach ($entries as $entry) {
            $child = $path.'/'.$entry;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }

        rmdir($path);
    }
}
