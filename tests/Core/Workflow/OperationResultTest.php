<?php

declare(strict_types=1);

namespace App\Tests\Core\Workflow;

use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;
use App\Core\Workflow\OperationStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OperationResultTest extends TestCase
{
    public function testSuccessResultCarriesValueAndContext(): void
    {
        $result = OperationResult::success('theme-default', [
            'source' => 'system',
        ]);

        self::assertSame(OperationStatus::Success, $result->status());
        self::assertTrue($result->isSuccess());
        self::assertFalse($result->isRecoverable());
        self::assertSame('theme-default', $result->value());
        self::assertFalse($result->hasIssues());
        self::assertNull($result->firstIssue());
        self::assertSame(['source' => 'system'], $result->context());
    }

    public function testInvalidResultCarriesIssues(): void
    {
        $issue = OperationIssue::create('content.title_missing', 'Content title is required.');

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
        $issue = OperationIssue::create('import.confirm_changes', 'Import changes require review.');
        $plan = ['changes' => 3];

        $result = OperationResult::requiresReview($plan, [$issue]);

        self::assertSame(OperationStatus::RequiresReview, $result->status());
        self::assertTrue($result->isRecoverable());
        self::assertSame($plan, $result->value());
        self::assertSame($issue, $result->firstIssue());
    }

    public function testBlockedAndFailedResultsUseExpectedRecoverability(): void
    {
        $issue = OperationIssue::create('storage.unavailable', 'Configured storage is unavailable.');

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
