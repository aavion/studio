<?php

declare(strict_types=1);

namespace App\Tests\Core\Diff;

use App\Core\Diff\KeyValueDiffGenerator;
use App\Core\Diff\StructuredDiffChangeType;
use App\Core\Diff\StructuredDiffType;
use App\Core\Diff\TextDiffGenerator;
use PHPUnit\Framework\TestCase;

final class DiffGeneratorTest extends TestCase
{
    public function testTextDiffReportsChangedContent(): void
    {
        $diff = (new TextDiffGenerator())->diff('template', 'before', 'after');

        self::assertSame(StructuredDiffType::Text, $diff->type());
        self::assertTrue($diff->hasChanges());
        self::assertSame('template', $diff->changes()[0]->path());
        self::assertSame(StructuredDiffChangeType::Changed, $diff->changes()[0]->type());
        self::assertSame([
            'before' => 'before',
            'after' => 'after',
            'changes' => [[
                'path' => 'template',
                'type' => 'changed',
                'before' => 'before',
                'after' => 'after',
            ]],
        ], $diff->toPayload());
    }

    public function testTextDiffReportsNoChangesForEqualContent(): void
    {
        $diff = (new TextDiffGenerator())->diff('template', 'same', 'same');

        self::assertFalse($diff->hasChanges());
        self::assertSame([], $diff->changes());
    }

    public function testKeyValueDiffReportsAddedRemovedAndChangedKeys(): void
    {
        $diff = (new KeyValueDiffGenerator())->diff('manifest', [
            'removed' => 'old',
            'changed' => 'old',
            'same' => true,
        ], [
            'added' => 'new',
            'changed' => 'new',
            'same' => true,
        ]);

        self::assertSame(StructuredDiffType::KeyValue, $diff->type());
        self::assertSame(['added', 'changed', 'removed'], $diff->payload()['changed_keys']);
        self::assertSame([
            ['path' => 'added', 'type' => 'added', 'after' => 'new'],
            ['path' => 'changed', 'type' => 'changed', 'before' => 'old', 'after' => 'new'],
            ['path' => 'removed', 'type' => 'removed', 'before' => 'old'],
        ], array_map(static fn ($change): array => $change->toArray(), $diff->changes()));
    }
}
