<?php

declare(strict_types=1);

namespace App\Tests\Core\Workflow;

use App\Core\Workflow\OperationIssue;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OperationIssueTest extends TestCase
{
    public function testItStoresCodeMessageAndContext(): void
    {
        $issue = OperationIssue::create('manifest.missing_key', 'Required manifest key is missing.', [
            'key' => 'MODULE_NAME',
        ]);

        self::assertSame('manifest.missing_key', $issue->code());
        self::assertSame('Required manifest key is missing.', $issue->message());
        self::assertSame(['key' => 'MODULE_NAME'], $issue->context());
    }

    public function testItRejectsEmptyCodes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation issue code must not be empty.');

        OperationIssue::create(' ', 'Required manifest key is missing.');
    }

    public function testItRejectsEmptyMessages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation issue message must not be empty.');

        OperationIssue::create('manifest.missing_key', ' ');
    }
}
