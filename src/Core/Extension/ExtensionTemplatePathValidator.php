<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use InvalidArgumentException;

final readonly class ExtensionTemplatePathValidator
{
    /**
     * @param list<string> $templateFiles
     *
     * @return list<Message>
     */
    public function validate(ExtensionCandidate $candidate, array $templateFiles): array
    {
        $scopeValue = $candidate->manifest()->get('EXTENSION_SCOPE');

        if (null === $scopeValue || '' === trim($scopeValue)) {
            return [];
        }

        try {
            $scopes = ExtensionScope::fromManifestValue($scopeValue);
        } catch (InvalidArgumentException) {
            return [];
        }

        $extensionSlug = $this->extensionSlug($candidate);
        $issues = [];

        foreach ($templateFiles as $file) {
            if (!$this->isAllowed($file, $extensionSlug, $scopes)) {
                $issues[] = Message::create(
                    ExtensionMessageCode::EXTENSION_TEMPLATE_PATH_INVALID,
                    ExtensionMessageKey::EXTENSION_TEMPLATE_PATH_INVALID,
                    ['%path%' => $file, '%scope%' => $scopeValue],
                    context: [
                        'source' => $candidate->source()->name(),
                        'extension' => $candidate->directory(),
                        'extension_slug' => $extensionSlug,
                        'file' => $file,
                        'scopes' => array_map(static fn (ExtensionScope $scope): string => $scope->value, $scopes),
                    ],
                    level: MessageLevel::Error,
                );
            }
        }

        return $issues;
    }

    /**
     * @param list<ExtensionScope> $scopes
     */
    private function isAllowed(string $file, string $extensionSlug, array $scopes): bool
    {
        if (str_starts_with($file, 'templates/frontend/')) {
            return true;
        }

        if (str_starts_with($file, 'templates/backend/')) {
            return true;
        }

        if (str_starts_with($file, 'templates/provider/')) {
            return $this->isAllowedProviderPath($file, $scopes);
        }

        if (str_starts_with($file, 'templates/macros/')) {
            return $this->isAllowedMacroPath($file, $extensionSlug, $scopes);
        }

        return $this->hasScope($scopes, ExtensionScope::SystemTemplate);
    }

    /**
     * @param list<ExtensionScope> $scopes
     */
    private function isAllowedProviderPath(string $file, array $scopes): bool
    {
        $relative = substr($file, strlen('templates/provider/'));
        $provider = strtok($relative, '/');

        if (false === $provider || '' === $provider) {
            return false;
        }

        foreach ($scopes as $scope) {
            if ($scope->value === $provider.'-provider') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<ExtensionScope> $scopes
     */
    private function isAllowedMacroPath(string $file, string $extensionSlug, array $scopes): bool
    {
        $relative = substr($file, strlen('templates/macros/'));

        if (str_starts_with($relative, 'core/')) {
            return $this->hasScope($scopes, ExtensionScope::SystemTemplate);
        }

        if (str_starts_with($relative, $extensionSlug.'/')) {
            return true;
        }

        return false;
    }

    /**
     * @param list<ExtensionScope> $scopes
     */
    private function hasScope(array $scopes, ExtensionScope $scope): bool
    {
        return in_array($scope, $scopes, true);
    }

    private function extensionSlug(ExtensionCandidate $candidate): string
    {
        $slug = trim((string) $candidate->manifest()->get('EXTENSION_SLUG', ''));

        return ExtensionManifestSpec::isValidSlug($slug) ? $slug : 'extension';
    }
}
