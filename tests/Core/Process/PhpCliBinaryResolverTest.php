<?php

declare(strict_types=1);

namespace App\Tests\Core\Process;

use App\Core\Process\PhpCliBinaryResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PhpCliBinaryResolverTest extends TestCase
{
    public function testItResolvesRunnablePhpCliCommandPrefix(): void
    {
        $resolution = (new PhpCliBinaryResolver())->resolve(__DIR__);

        self::assertTrue($resolution->isAvailable(), $resolution->reason());
        self::assertNotSame([], $resolution->commandPrefix());

        $process = new Process([...$resolution->commandPrefix(), '-r', 'echo PHP_SAPI;'], __DIR__, timeout: 5.0);
        $process->run();

        self::assertTrue($process->isSuccessful());
        self::assertSame('cli', trim($process->getOutput()));
    }
}
