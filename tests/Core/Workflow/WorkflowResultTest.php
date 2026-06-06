<?php

declare(strict_types=1);

namespace App\Tests\Core\Workflow;

use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Operation\OperationMessageKey;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Core\Workflow\WorkflowStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WorkflowResultTest extends TestCase
{
    public function testSuccessResultCarriesValueAndContext(): void
    {
        $message = Message::info(PackageMessageCode::PACKAGE_DISCOVERY_COMPLETED, PackageMessageKey::PACKAGE_DISCOVERY_COMPLETED, [
            '%count%' => 1,
        ]);
        $result = WorkflowResult::success('theme-default', [
            'source' => 'system',
        ], [
            $message,
        ]);

        self::assertSame(WorkflowStatus::Success, $result->status());
        self::assertTrue($result->isSuccess());
        self::assertFalse($result->isRecoverable());
        self::assertSame('theme-default', $result->value());
        self::assertFalse($result->hasIssues());
        self::assertNull($result->firstIssue());
        self::assertSame([$message], $result->messages());
        self::assertSame(MessageLevel::Info->value, $result->toArray()['messages'][0]['level']);
        self::assertSame(['source' => 'system'], $result->context());
    }

    public function testInvalidResultCarriesIssues(): void
    {
        $issue = Message::create('content.title_missing', 'message.content.title.required');

        $result = WorkflowResult::invalid([$issue], [
            'content_type' => 'page',
        ]);

        self::assertSame(WorkflowStatus::Invalid, $result->status());
        self::assertFalse($result->isSuccess());
        self::assertTrue($result->isRecoverable());
        self::assertNull($result->value());
        self::assertTrue($result->hasIssues());
        self::assertSame([$issue], $result->issues());
        self::assertSame($issue, $result->firstIssue());
        self::assertSame(['content_type' => 'page'], $result->context());
    }

    public function testRequiresReviewResultCanCarryAReviewPlan(): void
    {
        $issue = Message::create('import.confirm_changes', 'message.import.confirm_changes');
        $plan = ['changes' => 3];

        $result = WorkflowResult::requiresReview($plan, [$issue]);

        self::assertSame(WorkflowStatus::RequiresReview, $result->status());
        self::assertTrue($result->isRecoverable());
        self::assertSame($plan, $result->value());
        self::assertSame($issue, $result->firstIssue());
    }

    public function testItExportsStructuredPayload(): void
    {
        $issue = Message::create('import.confirm_changes', 'message.import.confirm_changes', [
            '%changes%' => 3,
        ], [
            'changes' => 3,
        ]);

        $result = WorkflowResult::requiresReview(['plan' => 'demo'], [$issue], [
            'queue' => 'import',
        ]);

        self::assertSame([
            'status' => 'requires_review',
            'success' => false,
            'recoverable' => true,
            'value' => ['plan' => 'demo'],
            'issues' => [[
                'level' => 'WARN',
                'code' => 'import.confirm_changes',
                'translation_key' => 'message.import.confirm_changes',
                'parameters' => ['%changes%' => 3],
                'context' => ['changes' => 3],
            ]],
            'messages' => [],
            'context' => ['queue' => 'import'],
        ], $result->toArray());
    }

    public function testBlockedAndFailedResultsUseExpectedRecoverability(): void
    {
        $issue = Message::create('storage.unavailable', 'message.storage.unavailable');

        $blocked = WorkflowResult::blocked([$issue]);
        $failed = WorkflowResult::failed([$issue]);

        self::assertSame(WorkflowStatus::Blocked, $blocked->status());
        self::assertTrue($blocked->isRecoverable());
        self::assertSame(WorkflowStatus::Failed, $failed->status());
        self::assertFalse($failed->isRecoverable());
    }

    public function testIssueStatusesRequireAtLeastOneIssue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow result status "invalid" requires at least one issue.');

        WorkflowResult::invalid([]);
    }

    public function testRequiresReviewRequiresAUserFacingPromptIssue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow result status "requires_review" requires a user-facing confirmation prompt issue.');

        WorkflowResult::requiresReview(null, [
            Message::error(CommonMessageCode::E_OPERATION_FAILED, OperationMessageKey::OPERATION_EXCEPTION),
        ]);
    }

    public function testIssuesMustBeMessageInstances(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow result issues must contain only Message instances.');

        /** @phpstan-ignore-next-line */
        WorkflowResult::failed(['broken']);
    }
}
