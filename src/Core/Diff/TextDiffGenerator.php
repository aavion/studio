<?php

declare(strict_types=1);

namespace App\Core\Diff;

final class TextDiffGenerator implements DiffGeneratorInterface
{
    public function diff(string $label, mixed $before, mixed $after): StructuredDiff
    {
        $before = (string) $before;
        $after = (string) $after;
        $changes = $before === $after ? [] : [StructuredDiffChange::changed($label, $before, $after)];

        return new StructuredDiff(
            StructuredDiffType::Text,
            $label,
            $changes,
            [
                'before' => $before,
                'after' => $after,
            ],
        );
    }
}
