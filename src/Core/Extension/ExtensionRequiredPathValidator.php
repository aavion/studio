<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;

final readonly class ExtensionRequiredPathValidator
{
    public function __construct(private ExtensionValidationIssueFactory $issueFactory = new ExtensionValidationIssueFactory())
    {
    }

    /**
     * @return list<Message>
     */
    public function validate(ExtensionCandidate $candidate, ExtensionSpec $spec): array
    {
        $issues = [];

        foreach ($spec->requiredFiles() as $path) {
            $absolutePath = $candidate->directory().DIRECTORY_SEPARATOR.$path;
            if (!is_file($absolutePath)) {
                $issues[] = Message::create(
                    ExtensionMessageCode::EXTENSION_REQUIRED_FILE_MISSING,
                    ExtensionMessageKey::EXTENSION_REQUIRED_FILE_MISSING,
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
                    ExtensionMessageCode::EXTENSION_REQUIRED_DIRECTORY_MISSING,
                    ExtensionMessageKey::EXTENSION_REQUIRED_DIRECTORY_MISSING,
                    ['%path%' => $absolutePath],
                    context: $this->issueFactory->requirementContext($candidate, $path, $absolutePath),
                    level: MessageLevel::Error,
                );
            }
        }

        return $issues;
    }
}
