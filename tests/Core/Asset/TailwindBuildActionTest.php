<?php

declare(strict_types=1);

namespace App\Tests\Core\Asset;

use App\Core\Asset\AssetMessageCode;
use App\Core\Asset\TailwindBuildAction;
use App\Core\Operation\Process\ProcessMessageCode;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class TailwindBuildActionTest extends TestCase
{
    use FilesystemTestHelper;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('studio-tailwind-action');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItSucceedsWhenTailwindCommandSucceeds(): void
    {
        $result = (new TailwindBuildAction([PHP_BINARY, '-r', 'exit(0);'], $this->root))->execute();

        self::assertTrue($result->isSuccess());
        self::assertTrue($result->value()['tailwind_executed'] ?? false);
        self::assertSame([], $result->issues());
    }

    public function testItFailsWhenTailwindCommandReportsBuildErrors(): void
    {
        $result = (new TailwindBuildAction([PHP_BINARY, '-r', 'fwrite(STDERR, "blocked"); exit(1);'], $this->root))->execute();

        self::assertFalse($result->isSuccess());
        self::assertSame(ProcessMessageCode::PROCESS_COMMAND_FAILED, $result->firstIssue()?->code());
        self::assertFalse($result->context()['tailwind_executed'] ?? true);
        self::assertSame('php bin/console tailwind:build', $result->context()['manual_command'] ?? null);
    }

    public function testItDefersTailwindProcessStartExceptionsWithoutFailingTheQueue(): void
    {
        $result = (new TailwindBuildAction([''], $this->root))->execute();

        self::assertTrue($result->isSuccess());
        self::assertFalse($result->value()['tailwind_executed'] ?? true);
        self::assertSame(AssetMessageCode::TAILWIND_BUILD_DEFERRED, $result->messages()[0]->code());
    }
}
