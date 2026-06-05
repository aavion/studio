<?php

declare(strict_types=1);

namespace App\Tests\Core\Console;

use App\Core\Console\ConsoleWorkflowResultRenderer;
use App\Core\Message\Message;
use App\Core\Workflow\WorkflowResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;

final class ConsoleWorkflowResultRendererTest extends TestCase
{
    public function testItReturnsSuccessWithoutWritingEmptyResults(): void
    {
        $output = new BufferedOutput();
        $exitCode = (new ConsoleWorkflowResultRenderer())->write($output, WorkflowResult::success());

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('', $output->fetch());
    }

    public function testItWritesIssuesAndMessagesAndReturnsFailure(): void
    {
        $output = new BufferedOutput();
        $result = WorkflowResult::failed([
            Message::warning('workflow.issue', 'message.workflow.issue'),
        ], messages: [
            Message::info('workflow.message', 'message.workflow.message'),
        ]);

        $exitCode = (new ConsoleWorkflowResultRenderer())->write($output, $result);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(
            "[WARN] message.workflow.issue\n[INFO] message.workflow.message\n",
            $output->fetch(),
        );
    }
}
