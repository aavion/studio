<?php

declare(strict_types=1);

namespace App\Tests\Core\Workflow;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;
use App\Core\Workflow\OperationStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OperationResultTest extends TestCase
{
    public function testSuccessResultCarriesValueAndContext(): void
    {
        $message = Message::info(MessageCode::PACKAGE_DISCOVERY_COMPLETED, MessageKey::PACKAGE_DISCOVERY_COMPLETED, [
            '%count%' => 1,
        ]);
        $result = OperationResult::success('theme-default', [
            'source' => 'system',
        ], [
            $message,
        ]);

        self::assertSame(OperationStatus::Success, $result->status());
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
        $issue = OperationIssue::create('content.title_missing', 'message.content.title.required');

        $result = OperationResult::invalid([$issue], [
            'content_type' => 'page',
        ]);

        self::assertSame(OperationStatus::Invalid, $result->status());
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
        $issue = OperationIssue::create('import.confirm_changes', 'message.import.confirm_changes');
        $plan = ['changes' => 3];

        $result = OperationResult::requiresReview($plan, [$issue]);

        self::assertSame(OperationStatus::RequiresReview, $result->status());
        self::assertTrue($result->isRecoverable());
        self::assertSame($plan, $result->value());
        self::assertSame($issue, $result->firstIssue());
    }

    public function testItExportsStructuredPayload(): void
    {
        $issue = OperationIssue::create('import.confirm_changes', 'message.import.confirm_changes', [
            '%changes%' => 3,
        ], [
            'changes' => 3,
        ]);

        $result = OperationResult::requiresReview(['plan' => 'demo'], [$issue], [
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
        $issue = OperationIssue::create('storage.unavailable', 'message.storage.unavailable');

        $blocked = OperationResult::blocked([$issue]);
        $failed = OperationResult::failed([$issue]);

        self::assertSame(OperationStatus::Blocked, $blocked->status());
        self::assertTrue($blocked->isRecoverable());
        self::assertSame(OperationStatus::Failed, $failed->status());
        self::assertFalse($failed->isRecoverable());
    }

    public function testIssueStatusesRequireAtLeastOneIssue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation result status "invalid" requires at least one issue.');

        OperationResult::invalid([]);
    }

    public function testIssuesMustBeOperationIssueInstances(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation result issues must contain only OperationIssue instances.');

        /** @phpstan-ignore-next-line */
        OperationResult::failed(['broken']);
    }
}
