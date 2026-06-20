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
            $scopeAliases = $this->scopeAliasesForFile($candidate, $file);
            $prefixes = $this->expectedPrefixes($extension, $scopeAliases);
            $path = $candidate->directory().DIRECTORY_SEPARATOR.$file;
            $contents = file_get_contents($path);

            if (false === $contents) {
                $issues[] = $this->issueFactory->unreadableFile($candidate, $file, $path);
                continue;
            }

            foreach ($this->targetClassSelectors($contents) as $class) {
                if ($this->isOwnedTargetClass($class, $extension, $scopeAliases)) {
                    continue;
                }

                $issues[] = Message::create(
                    ExtensionMessageCode::EXTENSION_CSS_NAMESPACE_INVALID,
                    ExtensionMessageKey::EXTENSION_CSS_NAMESPACE_INVALID,
                    ['%path%' => $path, '%class%' => $class, '%expected_prefix%' => implode(' or ', $prefixes)],
                    context: $this->issueFactory->fileContext($candidate, $file, $path, [
                        'class' => $class,
                        'css_scopes' => $scopeAliases,
                        'expected_prefixes' => $prefixes,
                        'expected_prefix' => $prefixes[0],
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

        return ExtensionManifestSpec::isValidSlug($slug) ? $slug : '';
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

    /**
     * @param list<string> $scopeAliases
     */
    private function isOwnedTargetClass(string $class, string $extension, array $scopeAliases): bool
    {
        if (!str_starts_with($class, $extension.'-')) {
            return false;
        }

        foreach (['frontend', 'backend', 'captcha', 'editor'] as $knownScopeAlias) {
            if (str_starts_with($class, $extension.'-'.$knownScopeAlias.'-')) {
                return in_array($knownScopeAlias, $scopeAliases, true);
            }
        }

        return true;
    }

    /**
     * @param list<string> $scopeAliases
     *
     * @return non-empty-list<string>
     */
    private function expectedPrefixes(string $extension, array $scopeAliases): array
    {
        $prefixes = [$extension.'-'];

        foreach ($scopeAliases as $scopeAlias) {
            $prefixes[] = $extension.'-'.$scopeAlias.'-';
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * @return list<string>
     */
    private function scopeAliasesForFile(ExtensionCandidate $candidate, string $file): array
    {
        $file = str_replace('\\', '/', $file);

        if (str_starts_with($file, 'assets/frontend/')) {
            return ['frontend'];
        }

        if (str_starts_with($file, 'assets/backend/')) {
            return ['backend'];
        }

        if (preg_match('#\Aassets/provider/([a-z][a-z0-9-]*)/#', $file, $matches)) {
            return [$matches[1]];
        }

        return array_values(array_unique(array_filter(array_map(
            $this->scopeAlias(...),
            $this->manifestScopes($candidate),
        ))));
    }

    private function scopeAlias(ExtensionScope $scope): ?string
    {
        return match ($scope) {
            ExtensionScope::FrontendTheme => 'frontend',
            ExtensionScope::BackendTheme => 'backend',
            ExtensionScope::CaptchaProvider => 'captcha',
            ExtensionScope::EditorProvider => 'editor',
            default => null,
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
