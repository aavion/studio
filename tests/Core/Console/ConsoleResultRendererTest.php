<?php

declare(strict_types=1);

namespace App\Tests\Core\Console;

use App\Core\Console\ConsoleResultRenderer;
use App\Core\Message\Message;
use App\Core\Workflow\WorkflowResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;

final class ConsoleResultRendererTest extends TestCase
{
    public function testItReturnsSuccessWithoutWritingEmptyResults(): void
    {
        $output = new BufferedOutput();
        $exitCode = (new ConsoleResultRenderer())->writeWorkflow($output, WorkflowResult::success());

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

        $exitCode = (new ConsoleResultRenderer())->writeWorkflow($output, $result);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(
            "[WARN] message.workflow.issue\n[INFO] message.workflow.message\n",
            str_replace("\r\n", "\n", $output->fetch()),
        );
    }

    public function testItWritesJsonPayloads(): void
    {
        $output = new BufferedOutput();

        $exitCode = (new ConsoleResultRenderer())->writePayload($output, [
            'status' => 'success',
            'path' => 'packages/demo',
            'label' => 'Overview',
        ], pretty: true);
        $display = $output->fetch();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertJson($display);
        self::assertStringContainsString('"path": "packages/demo"', $display);
    }

    public function testItMapsStatusExitCodes(): void
    {
        $renderer = new ConsoleResultRenderer();

        self::assertSame(Command::SUCCESS, $renderer->statusExitCode('success'));
        self::assertSame(Command::FAILURE, $renderer->statusExitCode('failed'));
    }
}
