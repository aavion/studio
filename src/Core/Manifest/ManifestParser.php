<?php

declare(strict_types=1);

namespace App\Core\Manifest;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
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
                OperationIssue::create(MessageCode::MANIFEST_UNREADABLE, MessageKey::MANIFEST_UNREADABLE, level: MessageLevel::Error),
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
                    MessageCode::MANIFEST_INVALID_LINE,
                    MessageKey::MANIFEST_INVALID_LINE,
                    ['%line%' => $lineNumber],
                    context: ['line' => $lineNumber],
                    level: MessageLevel::Warning,
                );

                continue;
            }

            [$rawKey, $rawValue] = explode('=', $line, 2);
            $key = trim($rawKey);
            $value = $this->normalizeValue($rawValue);

            if (!ManifestKey::isValid($key)) {
                $issues[] = OperationIssue::create(
                    MessageCode::MANIFEST_INVALID_KEY,
                    MessageKey::MANIFEST_INVALID_KEY,
                    ['%key%' => $key],
                    context: ['line' => $lineNumber, 'key' => $key],
                    level: MessageLevel::Warning,
                );

                continue;
            }

            if (array_key_exists($key, $values)) {
                $issues[] = OperationIssue::create(
                    MessageCode::MANIFEST_DUPLICATE_KEY,
                    MessageKey::MANIFEST_DUPLICATE_KEY,
                    ['%key%' => $key],
                    context: ['line' => $lineNumber, 'key' => $key],
                    level: MessageLevel::Warning,
                );

                continue;
            }

            $values[$key] = $value;
        }

        if ([] !== $issues) {
            return OperationResult::invalid($issues);
        }

        return OperationResult::success(new Manifest($values), [
            'keys' => array_keys($values),
            'key_count' => count($values),
        ], [
            Message::debug(MessageCode::MANIFEST_PARSED, MessageKey::MANIFEST_PARSED, context: [
                'keys' => array_keys($values),
                'key_count' => count($values),
            ]),
        ]);
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
