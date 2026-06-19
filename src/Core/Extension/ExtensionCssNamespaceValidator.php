<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;

final readonly class ExtensionCssNamespaceValidator
{
    public function __construct(private ExtensionValidationIssueFactory $issueFactory = new ExtensionValidationIssueFactory())
    {
    }

    /**
     * @param list<string> $cssFiles
     *
     * @return list<Message>
     */
    public function validate(ExtensionCandidate $candidate, array $cssFiles): array
    {
        $extension = $this->extensionSlug($candidate);

        if ('' === $extension) {
            return [];
        }

        $issues = [];

        foreach ($cssFiles as $file) {
            $scope = $this->scopeForFile($candidate, $file);
            $path = $candidate->directory().DIRECTORY_SEPARATOR.$file;
            $contents = file_get_contents($path);

            if (false === $contents) {
                $issues[] = $this->issueFactory->unreadableFile($candidate, $file, $path);
                continue;
            }

            foreach ($this->targetClassSelectors($contents) as $class) {
                if ($this->isOwnedTargetClass($class, $extension, $scope)) {
                    continue;
                }

                $issues[] = Message::create(
                    ExtensionMessageCode::EXTENSION_CSS_NAMESPACE_INVALID,
                    ExtensionMessageKey::EXTENSION_CSS_NAMESPACE_INVALID,
                    ['%path%' => $path, '%class%' => $class, '%expected_prefix%' => $this->expectedPrefix($extension, $scope)],
                    context: $this->issueFactory->fileContext($candidate, $file, $path, [
                        'class' => $class,
                        'css_scope' => $scope,
                        'expected_prefix' => $this->expectedPrefix($extension, $scope),
                    ]),
                    level: MessageLevel::Error,
                );
            }
        }

        return $issues;
    }

    private function extensionSlug(ExtensionCandidate $candidate): string
    {
        $slug = trim((string) $candidate->manifest()->get('EXTENSION_SLUG', ''));

        return 1 === preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', $slug) ? $slug : '';
    }

    /**
     * @return list<string>
     */
    private function targetClassSelectors(string $contents): array
    {
        $normalized = preg_replace([
            '#/\*.*?\*/#s',
            '#"(?:\\\\.|[^"\\\\])*"#s',
            "#'(?:\\\\.|[^'\\\\])*'#s",
        ], '', $contents) ?? $contents;

        $classes = [];

        foreach (explode('{', $normalized) as $block) {
            $selectors = substr($block, (int) (strrpos($block, '}') ?: -1) + 1);

            if (str_starts_with(trim($selectors), '@')) {
                continue;
            }

            foreach (explode(',', $selectors) as $selector) {
                $target = $this->targetSelectorPart($selector);
                preg_match_all('/(?<![A-Za-z0-9_-])\.([A-Za-z_-][A-Za-z0-9_-]*)/', $target, $matches);
                $targetClasses = $matches[1] ?? [];

                if ([] !== $targetClasses) {
                    $classes[] = end($targetClasses);
                }
            }
        }

        return array_values(array_unique(array_filter($classes, 'is_string')));
    }

    private function targetSelectorPart(string $selector): string
    {
        $parts = preg_split('/\s+|[>+~]/', trim($selector)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => '' !== $part));

        return [] === $parts ? '' : $parts[array_key_last($parts)];
    }

    private function isOwnedTargetClass(string $class, string $extension, string $scope): bool
    {
        return str_starts_with($class, $this->expectedPrefix($extension, $scope));
    }

    private function expectedPrefix(string $extension, string $scope): string
    {
        return match ($scope) {
            'frontend' => $extension.'-frontend-',
            'backend' => $extension.'-backend-',
            'captcha', 'editor' => $extension.'-'.$scope.'-',
            default => $extension.'-',
        };
    }

    private function scopeForFile(ExtensionCandidate $candidate, string $file): string
    {
        $file = str_replace('\\', '/', $file);

        if (str_starts_with($file, 'assets/frontend/')) {
            return 'frontend';
        }

        if (str_starts_with($file, 'assets/backend/')) {
            return 'backend';
        }

        if (preg_match('#\Aassets/provider/([a-z][a-z0-9-]*)/#', $file, $matches)) {
            return $matches[1];
        }

        $scopes = $this->manifestScopes($candidate);

        return match ($scopes) {
            [ExtensionScope::FrontendTheme] => 'frontend',
            [ExtensionScope::BackendTheme] => 'backend',
            [ExtensionScope::CaptchaProvider] => 'captcha',
            [ExtensionScope::EditorProvider] => 'editor',
            default => 'root',
        };
    }

    /**
     * @return list<ExtensionScope>
     */
    private function manifestScopes(ExtensionCandidate $candidate): array
    {
        $scopeValue = trim((string) $candidate->manifest()->get('EXTENSION_SCOPE', ''));

        if ('' === $scopeValue) {
            return [];
        }

        try {
            return ExtensionScope::fromManifestValue($scopeValue);
        } catch (\InvalidArgumentException) {
            return [];
        }
    }
}
