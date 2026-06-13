<?php

declare(strict_types=1);

namespace App\Tests\Core\Operation;

use App\Core\Message\MessageLevel;
use App\Core\Operation\ActionQueue;
use App\Core\Operation\OperationExecutor;
use App\Core\Operation\Process\RunCommandAction;
use App\Core\Workflow\WorkflowStatus;
use InvalidArgumentException;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use PHPUnit\Framework\TestCase;

final class RunCommandActionTest extends TestCase
{
    public function testItBuildsDryRunPayloadWithoutEnvironmentValues(): void
    {
        $action = new RunCommandAction([PHP_BINARY, '-v'], __DIR__, [
            'SECRET_TOKEN' => 'hidden',
        ], label: 'Inspect PHP runtime');

        $dryRun = $action->dryRun();

        self::assertSame('run_command', $dryRun->type());
        self::assertSame('Inspect PHP runtime', $dryRun->label());
        self::assertSame('Inspect PHP runtime', $dryRun->context()['label']);
        self::assertSame([PHP_BINARY, '-v'], $dryRun->context()['command']);
        self::assertSame(__DIR__, $dryRun->context()['cwd']);
        self::assertSame(['SECRET_TOKEN'], $dryRun->context()['env_keys']);
        self::assertArrayNotHasKey('env', $dryRun->context());
    }

    public function testItExecutesSuccessfulCommands(): void
    {
        $action = new RunCommandAction([PHP_BINARY, '-r', 'echo "hello";']);
        $execution = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->executeQueue(ActionQueue::create('process', [$action]));

        self::assertTrue($execution->result()->isSuccess());
        self::assertSame('hello', $execution->actionLog()->entries()[0]->context()['output_excerpt']);
        self::assertSame(0, $execution->actionLog()->entries()[0]->context()['exit_code']);
        self::assertSame('Run command '.implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-r', 'echo "hello";'])), $execution->actionLog()->entries()[0]->context()['label']);
        self::assertSame(MessageLevel::Success, $execution->actionLog()->entries()[0]->messages()[0]->level());
    }

    public function testItDoesNotPassInheritedWebContextToCommands(): void
    {
        $previousProcessValue = getenv('HTTP_HOST');
        $serverExists = array_key_exists('HTTP_HOST', $_SERVER);
        $serverValue = $_SERVER['HTTP_HOST'] ?? null;
        putenv('HTTP_HOST=example.test');
        $_SERVER['HTTP_HOST'] = 'example.test';

        try {
            $action = new RunCommandAction([PHP_BINARY, '-r', 'echo getenv("HTTP_HOST") === false ? "unset" : getenv("HTTP_HOST");']);
            $result = $action->execute();
        } finally {
            false === $previousProcessValue ? putenv('HTTP_HOST') : putenv('HTTP_HOST='.$previousProcessValue);
            if ($serverExists) {
                $_SERVER['HTTP_HOST'] = $serverValue;
            } else {
                unset($_SERVER['HTTP_HOST']);
            }
        }

        self::assertTrue($result->isSuccess());
        self::assertSame('unset', $result->context()['output_excerpt']);
    }

    public function testItUsesCustomLabelsForUserFacingProcessMessages(): void
    {
        $action = new RunCommandAction([PHP_BINARY, '-r', 'echo "hello";'], label: 'Apply reviewed ACL group change');
        $result = $action->execute();

        self::assertTrue($result->isSuccess());
        self::assertSame('Apply reviewed ACL group change', $result->context()['label']);
        self::assertSame('Apply reviewed ACL group change', $result->messages()[0]->parameters()['%command%']);
    }

    public function testItMapsNonZeroExitCodesToFailedResults(): void
    {
        $action = new RunCommandAction([PHP_BINARY, '-r', 'fwrite(STDERR, "nope"); exit(7);']);
        $execution = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->executeQueue(ActionQueue::create('process', [$action]));

        self::assertSame(WorkflowStatus::Failed, $execution->result()->status());
        self::assertSame('process.command_failed', $execution->result()->firstIssue()?->code());
        self::assertSame(7, $execution->result()->firstIssue()?->context()['exit_code']);
        self::assertIsString($execution->result()->firstIssue()?->context()['exit_code_text']);
        self::assertFalse($execution->result()->firstIssue()?->context()['signaled']);
        self::assertNull($execution->result()->firstIssue()?->context()['term_signal']);
        self::assertSame('nope', $execution->actionLog()->entries()[0]->context()['error_excerpt']);
    }

    public function testItCanTreatNonZeroExitCodesAsWarnings(): void
    {
        $action = new RunCommandAction([PHP_BINARY, '-r', 'fwrite(STDERR, "offline"); exit(7);'], failOnError: false);
        $execution = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->executeQueue(ActionQueue::create('process', [$action]));

        self::assertTrue($execution->result()->isSuccess());
        self::assertSame(MessageLevel::Warning, $execution->actionLog()->entries()[0]->messages()[0]->level());
        self::assertSame('process.command_failed', $execution->actionLog()->entries()[0]->messages()[0]->code());
        self::assertSame(7, $execution->actionLog()->entries()[0]->context()['exit_code']);
        self::assertFalse($execution->actionLog()->entries()[0]->context()['fail_on_error']);
        self::assertSame('offline', $execution->actionLog()->entries()[0]->context()['error_excerpt']);
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
