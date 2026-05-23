<?php

declare(strict_types=1);

namespace App\Tests\Core\Operation;

use App\Core\Message\MessageLevel;
use App\Core\Operation\ActionQueue;
use App\Core\Operation\OperationExecutor;
use App\Core\Operation\Process\RunCommandAction;
use App\Core\Workflow\OperationStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RunCommandActionTest extends TestCase
{
    public function testItBuildsDryRunPayloadWithoutEnvironmentValues(): void
    {
        $action = new RunCommandAction([PHP_BINARY, '-v'], __DIR__, [
            'SECRET_TOKEN' => 'hidden',
        ]);

        $dryRun = $action->dryRun();

        self::assertSame('run_command', $dryRun->type());
        self::assertSame([PHP_BINARY, '-v'], $dryRun->context()['command']);
        self::assertSame(__DIR__, $dryRun->context()['cwd']);
        self::assertSame(['SECRET_TOKEN'], $dryRun->context()['env_keys']);
        self::assertArrayNotHasKey('env', $dryRun->context());
    }

    public function testItExecutesSuccessfulCommands(): void
    {
        $action = new RunCommandAction([PHP_BINARY, '-r', 'echo "hello";']);
        $execution = (new OperationExecutor())->executeQueue(ActionQueue::create('process', [$action]));

        self::assertTrue($execution->result()->isSuccess());
        self::assertSame('hello', $execution->actionLog()->entries()[0]->context()['output_excerpt']);
        self::assertSame(0, $execution->actionLog()->entries()[0]->context()['exit_code']);
        self::assertSame(MessageLevel::Info, $execution->actionLog()->entries()[0]->messages()[0]->level());
    }

    public function testItMapsNonZeroExitCodesToFailedResults(): void
    {
        $action = new RunCommandAction([PHP_BINARY, '-r', 'fwrite(STDERR, "nope"); exit(7);']);
        $execution = (new OperationExecutor())->executeQueue(ActionQueue::create('process', [$action]));

        self::assertSame(OperationStatus::Failed, $execution->result()->status());
        self::assertSame('process.command_failed', $execution->result()->firstIssue()?->code());
        self::assertSame(7, $execution->result()->firstIssue()?->context()['exit_code']);
        self::assertSame('nope', $execution->actionLog()->entries()[0]->context()['error_excerpt']);
    }

    public function testItLimitsOutputExcerpts(): void
    {
        $action = new RunCommandAction([PHP_BINARY, '-r', 'echo "abcdef";'], excerptLength: 3);
        $result = $action->execute();

        self::assertTrue($result->isSuccess());
        self::assertSame('abc', $result->context()['output_excerpt']);
    }

    public function testItRejectsEmptyCommands(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RunCommandAction([]);
    }
}
