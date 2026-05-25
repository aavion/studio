<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\OperationIssue;
use InvalidArgumentException;

final readonly class PackageTemplatePathValidator
{
    /**
     * @param list<string> $templateFiles
     *
     * @return list<OperationIssue>
     */
    public function validate(PackageCandidate $candidate, array $templateFiles): array
    {
        $scopeValue = $candidate->manifest()->get('PACKAGE_SCOPE');

        if (null === $scopeValue || '' === trim($scopeValue)) {
            return [];
        }

        try {
            $scopes = PackageScope::fromManifestValue($scopeValue);
        } catch (InvalidArgumentException) {
            return [];
        }

        $packageSlug = $this->packageSlug($candidate);
        $issues = [];

        foreach ($templateFiles as $file) {
            if (!$this->isAllowed($file, $packageSlug, $scopes)) {
                $issues[] = OperationIssue::create(
                    MessageCode::PACKAGE_TEMPLATE_PATH_INVALID,
                    MessageKey::PACKAGE_TEMPLATE_PATH_INVALID,
                    ['%path%' => $file, '%scope%' => $scopeValue],
                    context: [
                        'source' => $candidate->source()->name(),
                        'package' => $candidate->directory(),
                        'package_slug' => $packageSlug,
                        'file' => $file,
                        'scopes' => array_map(static fn (PackageScope $scope): string => $scope->value, $scopes),
                    ],
                    level: MessageLevel::Warning,
                );
            }
        }

        return $issues;
    }

    /**
     * @param list<PackageScope> $scopes
     */
    private function isAllowed(string $file, string $packageSlug, array $scopes): bool
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
            return $this->isAllowedMacroPath($file, $packageSlug, $scopes);
        }

        return $this->hasScope($scopes, PackageScope::SystemTemplate);
    }

    /**
     * @param list<PackageScope> $scopes
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
     * @param list<PackageScope> $scopes
     */
    private function isAllowedMacroPath(string $file, string $packageSlug, array $scopes): bool
    {
        $relative = substr($file, strlen('templates/macros/'));

        if (str_starts_with($relative, 'core/')) {
            return $this->hasScope($scopes, PackageScope::SystemTemplate);
        }

        if (str_starts_with($relative, $packageSlug.'/')) {
            return true;
        }

        return false;
    }

    /**
     * @param list<PackageScope> $scopes
     */
    private function hasScope(array $scopes, PackageScope $scope): bool
    {
        return in_array($scope, $scopes, true);
    }

    private function packageSlug(PackageCandidate $candidate): string
    {
        $directory = str_replace('\\', '/', rtrim($candidate->directory(), '/'));
        $slug = basename($directory);

        return '' === $slug ? 'package' : $slug;
    }
}
