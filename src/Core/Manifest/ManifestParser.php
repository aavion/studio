<?php

declare(strict_types=1);

namespace App\Core\Manifest;

use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;

final class ManifestParser
{
    /**
     * @return OperationResult<Manifest>
     */
    public function parse(string $contents): OperationResult
    {
        $values = [];
        $issues = [];
        $lines = preg_split('/\R/', $contents);

        if (false === $lines) {
            return OperationResult::invalid([
                OperationIssue::create('manifest.unreadable', 'Manifest contents could not be split into lines.'),
            ]);
        }

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $trimmedLine = trim($line);

            if ('' === $trimmedLine || str_starts_with($trimmedLine, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                $issues[] = OperationIssue::create(
                    'manifest.invalid_line',
                    'Manifest line must use KEY=VALUE format.',
                    ['line' => $lineNumber],
                );

                continue;
            }

            [$rawKey, $rawValue] = explode('=', $line, 2);
            $key = trim($rawKey);
            $value = $this->normalizeValue($rawValue);

            if (!ManifestKey::isValid($key)) {
                $issues[] = OperationIssue::create(
                    'manifest.invalid_key',
                    'Manifest key must use uppercase letters, numbers, and underscores.',
                    ['line' => $lineNumber, 'key' => $key],
                );

                continue;
            }

            if (array_key_exists($key, $values)) {
                $issues[] = OperationIssue::create(
                    'manifest.duplicate_key',
                    'Manifest key is defined more than once.',
                    ['line' => $lineNumber, 'key' => $key],
                );

                continue;
            }

            $values[$key] = $value;
        }

        if ([] !== $issues) {
            return OperationResult::invalid($issues);
        }

        return OperationResult::success(new Manifest($values));
    }

    private function normalizeValue(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (('"' === $first && '"' === $last) || ("'" === $first && "'" === $last)) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
