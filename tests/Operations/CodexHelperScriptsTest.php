<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use PHPUnit\Framework\TestCase;

final class CodexHelperScriptsTest extends TestCase
{
    public function testCloudArtifactResolverExistsAndIsValidPhp(): void
    {
        $path = dirname(__DIR__, 2).'/.codex/resolve_cloud_artifacts.php';

        self::assertFileExists($path);
        self::assertSame(0, $this->lint($path));
    }

    public function testIgnoredArtifactCleanerExistsAndIsValidPhp(): void
    {
        $path = dirname(__DIR__, 2).'/.codex/clean_ignored_artifacts.php';

        self::assertFileExists($path);
        self::assertSame(0, $this->lint($path));
    }

    private function lint(string $path): int
    {
        $command = [PHP_BINARY, '-l', $path];
        $output = [];
        $exitCode = 1;

        exec(implode(' ', array_map('escapeshellarg', $command)), $output, $exitCode);

        return $exitCode;
    }
}
