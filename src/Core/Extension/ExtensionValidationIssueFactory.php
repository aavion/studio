<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;

final readonly class ExtensionValidationIssueFactory
{
    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function fileContext(ExtensionCandidate $candidate, string $file, string $path, array $extra = []): array
    {
        return [
            'source' => $candidate->source()->name(),
            'extension' => $candidate->directory(),
            ...$extra,
            'path' => $path,
            'file' => $file,
        ];
    }

    /**
     * @return array{source: string, extension: string, requirement: string, path: string}
     */
    public function requirementContext(ExtensionCandidate $candidate, string $requirement, string $path): array
    {
        return [
            'source' => $candidate->source()->name(),
            'extension' => $candidate->directory(),
            'requirement' => $requirement,
            'path' => $path,
        ];
    }

    public function unreadableFile(ExtensionCandidate $candidate, string $file, string $path): Message
    {
        return Message::create(
            ExtensionMessageCode::EXTENSION_FILE_UNREADABLE,
            ExtensionMessageKey::EXTENSION_FILE_UNREADABLE,
            ['%path%' => $path],
            context: $this->fileContext($candidate, $file, $path),
            level: MessageLevel::Error,
        );
    }
}
