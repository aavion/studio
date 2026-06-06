<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;

final readonly class PackageRequiredPathValidator
{
    public function __construct(private PackageValidationIssueFactory $issueFactory = new PackageValidationIssueFactory())
    {
    }

    /**
     * @return list<Message>
     */
    public function validate(PackageCandidate $candidate, PackageSpec $spec): array
    {
        $issues = [];

        foreach ($spec->requiredFiles() as $path) {
            $absolutePath = $candidate->directory().DIRECTORY_SEPARATOR.$path;
            if (!is_file($absolutePath)) {
                $issues[] = Message::create(
                    MessageCode::PACKAGE_REQUIRED_FILE_MISSING,
                    MessageKey::PACKAGE_REQUIRED_FILE_MISSING,
                    ['%path%' => $absolutePath],
                    context: $this->issueFactory->requirementContext($candidate, $path, $absolutePath),
                    level: MessageLevel::Error,
                );
            }
        }

        foreach ($spec->requiredDirectories() as $path) {
            $absolutePath = $candidate->directory().DIRECTORY_SEPARATOR.$path;
            if (!is_dir($absolutePath)) {
                $issues[] = Message::create(
                    MessageCode::PACKAGE_REQUIRED_DIRECTORY_MISSING,
                    MessageKey::PACKAGE_REQUIRED_DIRECTORY_MISSING,
                    ['%path%' => $absolutePath],
                    context: $this->issueFactory->requirementContext($candidate, $path, $absolutePath),
                    level: MessageLevel::Error,
                );
            }
        }

        return $issues;
    }
}
