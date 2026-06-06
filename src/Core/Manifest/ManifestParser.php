<?php

declare(strict_types=1);

namespace App\Core\Manifest;

use App\Core\Manifest\ManifestMessageCode;
use App\Core\Manifest\ManifestMessageKey;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;

final class ManifestParser
{
    /**
     * @return WorkflowResult<Manifest>
     */
    public function parse(string $contents): WorkflowResult
    {
        $values = [];
        $issues = [];
        $lines = preg_split('/\R/', $contents);

        if (false === $lines) {
            return WorkflowResult::invalid([
                Message::create(ManifestMessageCode::MANIFEST_UNREADABLE, ManifestMessageKey::MANIFEST_UNREADABLE, level: MessageLevel::Error),
            ]);
        }

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $trimmedLine = trim($line);

            if ('' === $trimmedLine || str_starts_with($trimmedLine, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                $issues[] = Message::create(
                    ManifestMessageCode::MANIFEST_INVALID_LINE,
                    ManifestMessageKey::MANIFEST_INVALID_LINE,
                    ['%line%' => $lineNumber],
                    context: ['line' => $lineNumber],
                    level: MessageLevel::Error,
                );

                continue;
            }

            [$rawKey, $rawValue] = explode('=', $line, 2);
            $key = trim($rawKey);
            $value = $this->normalizeValue($rawValue);

            if (!ManifestKey::isValid($key)) {
                $issues[] = Message::create(
                    ManifestMessageCode::MANIFEST_INVALID_KEY,
                    ManifestMessageKey::MANIFEST_INVALID_KEY,
                    ['%key%' => $key],
                    context: ['line' => $lineNumber, 'key' => $key],
                    level: MessageLevel::Error,
                );

                continue;
            }

            if (array_key_exists($key, $values)) {
                $issues[] = Message::create(
                    ManifestMessageCode::MANIFEST_DUPLICATE_KEY,
                    ManifestMessageKey::MANIFEST_DUPLICATE_KEY,
                    ['%key%' => $key],
                    context: ['line' => $lineNumber, 'key' => $key],
                    level: MessageLevel::Error,
                );

                continue;
            }

            $values[$key] = $value;
        }

        if ([] !== $issues) {
            return WorkflowResult::invalid($issues);
        }

        return WorkflowResult::success(new Manifest($values), [
            'keys' => array_keys($values),
            'key_count' => count($values),
        ], [
            Message::debug(ManifestMessageCode::MANIFEST_PARSED, ManifestMessageKey::MANIFEST_PARSED, context: [
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
