<?php

declare(strict_types=1);

namespace App\Tests\Core\Asset;

use App\Core\Asset\TailwindBuildAction;
use App\Core\Message\MessageCode;
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

    public function testItDefersFailedTailwindCommandWithoutFailingTheQueue(): void
    {
        $result = (new TailwindBuildAction([PHP_BINARY, '-r', 'fwrite(STDERR, "blocked"); exit(1);'], $this->root))->execute();

        self::assertTrue($result->isSuccess());
        self::assertFalse($result->value()['tailwind_executed'] ?? true);
        self::assertSame([], $result->issues());
        self::assertSame(MessageCode::TAILWIND_BUILD_DEFERRED, $result->messages()[0]->code());
        self::assertSame('php bin/console tailwind:build', $result->context()['manual_command'] ?? null);
    }

    public function testItDefersTailwindProcessStartExceptionsWithoutFailingTheQueue(): void
    {
        $missingBinary = $this->root.'/missing-tailwind';

        $result = (new TailwindBuildAction([$missingBinary, '--version'], $this->root))->execute();

        self::assertTrue($result->isSuccess());
        self::assertFalse($result->value()['tailwind_executed'] ?? true);
        self::assertSame(MessageCode::TAILWIND_BUILD_DEFERRED, $result->messages()[0]->code());
    }
}
