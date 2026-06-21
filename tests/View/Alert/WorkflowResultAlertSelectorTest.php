<?php

declare(strict_types=1);

namespace App\Tests\View\Alert;

use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
use App\View\Alert\WorkflowResultAlertSelector;
use PHPUnit\Framework\TestCase;

final class WorkflowResultAlertSelectorTest extends TestCase
{
    public function testItKeepsSuccessSeverityWhenSuccessResultStartsWithDiagnosticMessage(): void
    {
        $selector = new WorkflowResultAlertSelector();
        $message = Message::debug(
            'extension.dependency.resolved',
            'message.extension.dependency.resolved',
            ['%extension%' => 'demo'],
            ['internal' => true],
        );

        $alert = $selector->fromResult(WorkflowResult::success(messages: [$message]));

        self::assertSame(MessageLevel::Success, $alert->level());
        self::assertSame(CommonMessageCode::SUCCESS, $alert->code());
        self::assertSame('message.extension.dependency.resolved', $alert->translationKey());
        self::assertSame(['%extension%' => 'demo'], $alert->parameters());
        self::assertSame([], $alert->context());
    }

    public function testItPrefersExplicitSuccessMessages(): void
    {
        $selector = new WorkflowResultAlertSelector();
        $debug = Message::debug('extension.dependency.resolved', 'message.extension.dependency.resolved');
        $success = Message::success('message.extension.lifecycle.activated', ['%extension%' => 'demo']);

        $alert = $selector->fromResult(WorkflowResult::success(messages: [$debug, $success]));

        self::assertSame($success, $alert);
    }

    public function testItUsesFirstIssueForFailedResults(): void
    {
        $selector = new WorkflowResultAlertSelector();
        $issue = Message::error('extension.lifecycle.not_found', 'message.extension.lifecycle.not_found');

        $alert = $selector->fromResult(WorkflowResult::failed([$issue]));

        self::assertSame($issue, $alert);
    }
}
