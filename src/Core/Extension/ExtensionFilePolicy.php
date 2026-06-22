<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;

final readonly class ExtensionFilePolicy
{
    /**
     * @var list<string>
     */
    private const BLOCKED_ASSET_EXTENSIONS = [
        'asp',
        'aspx',
        'cgi',
        'fcgi',
        'html',
        'htm',
        'jsp',
        'php',
        'phtml',
        'phar',
        'pl',
        'py',
        'rb',
        'sh',
    ];

    /**
     * @var list<array{pattern: string, reason: string}>
     */
    private const BLOCKED_PATHS = [
        ['pattern' => '#^\.env(?:\.|$)#', 'reason' => 'environment_file'],
        ['pattern' => '#^(?:composer\.json|composer\.lock)$#', 'reason' => 'composer_dependency_payload_unsupported'],
        ['pattern' => '#^(?:bin|node_modules|public|var|vendor)(?:/|$)#', 'reason' => 'reserved_project_path'],
    ];

    /**
     * @return list<Message>
     */
    public function blockedIssues(ExtensionCandidate $candidate, ExtensionInspection $inspection): array
    {
        if (!$this->appliesTo($candidate)) {
            return [];
        }

        $issues = [];

        foreach ($inspection->inventory() as $entry) {
            $path = rtrim($entry, '/');

            if ($this->isIgnoredExtensionPath($path)) {
                continue;
            }

            if ($this->isNestedDependencyPayloadPath($path)) {
                continue;
            }

            foreach (self::BLOCKED_PATHS as $rule) {
                if (1 === preg_match($rule['pattern'], $path)) {
                    $issues[] = $this->issue($candidate, $entry, $rule['reason'], blocked: true);
                    continue 2;
                }
            }

            $reason = $this->boundaryViolation($path, str_ends_with($entry, '/'), $inspection);
            if (null !== $reason) {
                $issues[] = $this->issue($candidate, $entry, $reason, blocked: true);
            }
        }

        return $issues;
    }

    /**
     * @return list<Message>
     */
    public function warningMessages(ExtensionCandidate $candidate, ExtensionInspection $inspection): array
    {
        return [];
    }

    public static function isIgnoredExtensionPath(string $path): bool
    {
        $path = self::normalizedPath($path);

        return 1 === preg_match('#^(?:docs|tests|\.github|\.idea|\.vscode)(?:/|$)#', $path)
            || 1 === preg_match('#^(?:\.git|\.hg|\.svn)(?:/|$)#', $path)
            || 1 === preg_match('#^\.(?:git|hg|svn).+#', $path)
            || in_array($path, ['.editorconfig'], true);
    }

    public static function isDependencyPayloadPath(string $path): bool
    {
        $path = self::normalizedPath($path);

        return str_starts_with($path, 'assets/node_modules/')
            || 'assets/node_modules' === $path;
    }

    public static function isInspectableExtensionPath(string $path): bool
    {
        return !self::isIgnoredExtensionPath($path) && !self::isDependencyPayloadPath($path);
    }

    private static function isNestedDependencyPayloadPath(string $path): bool
    {
        $path = self::normalizedPath($path);

        return str_starts_with($path, 'assets/node_modules/');
    }

    private function issue(ExtensionCandidate $candidate, string $path, string $reason, bool $blocked): Message
    {
        return Message::create(
            $blocked ? ExtensionMessageCode::EXTENSION_POLICY_BLOCKED_PATH : ExtensionMessageCode::EXTENSION_POLICY_WARNED_PATH,
            $blocked ? ExtensionMessageKey::EXTENSION_POLICY_BLOCKED_PATH : ExtensionMessageKey::EXTENSION_POLICY_WARNED_PATH,
            ['%path%' => $path, '%reason%' => $reason],
            [
                'source' => $candidate->source()->name(),
                'extension' => $candidate->directory(),
                'path' => $path,
                'reason' => $reason,
                'policy' => 'extension.file_path',
            ],
            $blocked ? MessageLevel::Error : MessageLevel::Warning,
        );
    }

    private function appliesTo(ExtensionCandidate $candidate): bool
    {
        return 'extension' === $candidate->source()->name();
    }

    private function boundaryViolation(string $path, bool $directory, ExtensionInspection $inspection): ?string
    {
        if ($directory) {
            return $this->directoryBoundaryViolation($path, $inspection);
        }

        if ($this->isAssetPath($path) && $this->isBlockedAssetFile($path)) {
            return 'asset_executable_file';
        }

        if (str_ends_with($path, '.php') && 'extension.php' !== $path && !str_starts_with($path, 'src/')) {
            return 'php_outside_src';
        }

        if (str_ends_with($path, '.twig') && !str_starts_with($path, 'templates/')) {
            return 'template_outside_templates';
        }

        if (str_starts_with($path, 'languages/') && !$this->isLanguageFile($path)) {
            return 'language_path_invalid';
        }

        return null;
    }

    private function directoryBoundaryViolation(string $path, ExtensionInspection $inspection): ?string
    {
        if ('assets/node_modules' === $path && !$inspection->hasNodeDependencies()) {
            return 'node_dependency_manifest_missing';
        }

        return null;
    }

    private function isAssetPath(string $path): bool
    {
        return str_starts_with($path, 'assets/') || str_starts_with($path, 'private-assets/');
    }

    private function isBlockedAssetFile(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::BLOCKED_ASSET_EXTENSIONS, true);
    }

    private function isLanguageFile(string $path): bool
    {
        return 1 === preg_match('#^languages/[a-z][a-z0-9]*(?:[_-][A-Za-z0-9]+)*/[^/]+\.yaml$#', $path);
    }

    private static function normalizedPath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
