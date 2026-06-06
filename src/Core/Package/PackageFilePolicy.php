<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;

final readonly class PackageFilePolicy
{
    /**
     * @var list<array{pattern: string, reason: string}>
     */
    private const BLOCKED_PATHS = [
        ['pattern' => '#^\.env(?:\.|$)#', 'reason' => 'environment_file'],
        ['pattern' => '#^(?:\.git|\.hg|\.svn)(?:/|$)#', 'reason' => 'vcs_metadata'],
        ['pattern' => '#^(?:bin|node_modules|public|var|vendor)(?:/|$)#', 'reason' => 'reserved_project_path'],
        ['pattern' => '#^composer\.(?:json|lock)$#', 'reason' => 'package_dependency_manifest'],
    ];

    /**
     * @var list<array{pattern: string, reason: string}>
     */
    private const WARNING_PATHS = [
        ['pattern' => '#^(?:\.github|\.idea|\.vscode)(?:/|$)#', 'reason' => 'development_metadata'],
        ['pattern' => '#^(?:docs|tests)(?:/|$)#', 'reason' => 'non_runtime_payload'],
    ];

    /**
     * @return list<Message>
     */
    public function blockedIssues(PackageCandidate $candidate, PackageInspection $inspection): array
    {
        if (!$this->appliesTo($candidate)) {
            return [];
        }

        $issues = [];

        foreach ($inspection->inventory() as $entry) {
            $path = rtrim($entry, '/');

            foreach (self::BLOCKED_PATHS as $rule) {
                if (1 === preg_match($rule['pattern'], $path)) {
                    $issues[] = $this->issue($candidate, $entry, $rule['reason'], blocked: true);
                    continue 2;
                }
            }
        }

        return $issues;
    }

    /**
     * @return list<Message>
     */
    public function warningMessages(PackageCandidate $candidate, PackageInspection $inspection): array
    {
        if (!$this->appliesTo($candidate)) {
            return [];
        }

        $messages = [];

        foreach ($inspection->inventory() as $entry) {
            $path = rtrim($entry, '/');

            foreach (self::WARNING_PATHS as $rule) {
                if (1 === preg_match($rule['pattern'], $path)) {
                    $messages[] = $this->issue($candidate, $entry, $rule['reason'], blocked: false);
                    continue 2;
                }
            }
        }

        return $messages;
    }

    private function issue(PackageCandidate $candidate, string $path, string $reason, bool $blocked): Message
    {
        return Message::create(
            $blocked ? PackageMessageCode::PACKAGE_POLICY_BLOCKED_PATH : PackageMessageCode::PACKAGE_POLICY_WARNED_PATH,
            $blocked ? PackageMessageKey::PACKAGE_POLICY_BLOCKED_PATH : PackageMessageKey::PACKAGE_POLICY_WARNED_PATH,
            ['%path%' => $path, '%reason%' => $reason],
            [
                'source' => $candidate->source()->name(),
                'package' => $candidate->directory(),
                'path' => $path,
                'reason' => $reason,
                'policy' => 'package.file_path',
            ],
            $blocked ? MessageLevel::Error : MessageLevel::Warning,
        );
    }

    private function appliesTo(PackageCandidate $candidate): bool
    {
        return 'package' === $candidate->source()->name();
    }
}
