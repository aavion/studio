<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;

final readonly class PackageValidationIssueFactory
{
    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function fileContext(PackageCandidate $candidate, string $file, string $path, array $extra = []): array
    {
        return [
            'source' => $candidate->source()->name(),
            'package' => $candidate->directory(),
            ...$extra,
            'path' => $path,
            'file' => $file,
        ];
    }

    /**
     * @return array{source: string, package: string, requirement: string, path: string}
     */
    public function requirementContext(PackageCandidate $candidate, string $requirement, string $path): array
    {
        return [
            'source' => $candidate->source()->name(),
            'package' => $candidate->directory(),
            'requirement' => $requirement,
            'path' => $path,
        ];
    }

    public function unreadableFile(PackageCandidate $candidate, string $file, string $path): Message
    {
        return Message::create(
            PackageMessageCode::PACKAGE_FILE_UNREADABLE,
            PackageMessageKey::PACKAGE_FILE_UNREADABLE,
            ['%path%' => $path],
            context: $this->fileContext($candidate, $file, $path),
            level: MessageLevel::Error,
        );
    }
}
