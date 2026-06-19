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
        ['pattern' => '#^(?:bin|node_modules|public|var)(?:/|$)#', 'reason' => 'reserved_project_path'],
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

            if ($this->isIgnoredPackagePath($path)) {
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
    public function warningMessages(PackageCandidate $candidate, PackageInspection $inspection): array
    {
        return [];
    }

    public static function isIgnoredPackagePath(string $path): bool
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

        return str_starts_with($path, 'vendor/')
            || 'vendor' === $path
            || str_starts_with($path, 'assets/node_modules/')
            || 'assets/node_modules' === $path;
    }

    public static function isInspectablePackagePath(string $path): bool
    {
        return !self::isIgnoredPackagePath($path) && !self::isDependencyPayloadPath($path);
    }

    private static function isNestedDependencyPayloadPath(string $path): bool
    {
        $path = self::normalizedPath($path);

        return str_starts_with($path, 'vendor/')
            || str_starts_with($path, 'assets/node_modules/');
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

    private function boundaryViolation(string $path, bool $directory, PackageInspection $inspection): ?string
    {
        if ($directory) {
            return $this->directoryBoundaryViolation($path, $inspection);
        }

        if ($this->isAssetPath($path) && $this->isBlockedAssetFile($path)) {
            return 'asset_executable_file';
        }

        if (str_ends_with($path, '.php') && 'package.php' !== $path && !str_starts_with($path, 'src/')) {
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

    private function directoryBoundaryViolation(string $path, PackageInspection $inspection): ?string
    {
        if ('vendor' === $path && !$inspection->hasComposerDependencies()) {
            return 'composer_dependency_manifest_missing';
        }

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
        return 1 === preg_match('#^languages/[a-z][a-z0-9]*(?:[_-][A-Za-z0-9]+)*/[^/]+\.ya?ml$#', $path);
    }

    private static function normalizedPath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
