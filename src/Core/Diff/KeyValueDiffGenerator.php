<?php

declare(strict_types=1);

namespace App\Core\Diff;

final class KeyValueDiffGenerator implements DiffGeneratorInterface
{
    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function diff(string $label, mixed $before, mixed $after): StructuredDiff
    {
        $before = is_array($before) ? $before : [];
        $after = is_array($after) ? $after : [];
        $changes = [];
        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($keys);

        foreach ($keys as $key) {
            if (!array_key_exists($key, $before)) {
                $changes[] = StructuredDiffChange::added((string) $key, $after[$key]);
                continue;
            }

            if (!array_key_exists($key, $after)) {
                $changes[] = StructuredDiffChange::removed((string) $key, $before[$key]);
                continue;
            }

            if ($before[$key] !== $after[$key]) {
                $changes[] = StructuredDiffChange::changed((string) $key, $before[$key], $after[$key]);
            }
        }

        return new StructuredDiff(
            StructuredDiffType::KeyValue,
            $label,
            $changes,
            [
                'before' => $before,
                'after' => $after,
                'changed_keys' => array_map(static fn (StructuredDiffChange $change): string => $change->path(), $changes),
            ],
        );
    }
}
