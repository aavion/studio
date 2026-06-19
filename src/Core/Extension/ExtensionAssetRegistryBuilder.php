<?php

declare(strict_types=1);

namespace App\Core\Extension;

final class ExtensionAssetRegistryBuilder
{
    public const BUCKET_EXTENSION = 'extension';
    public const BUCKET_FRONTEND_THEME = 'frontend-theme';
    public const BUCKET_BACKEND_THEME = 'backend-theme';

    /**
     * @param iterable<ExtensionAssetContribution> $contributions
     */
    public function buildCssRegistry(iterable $contributions, string $bucket): string
    {
        $lines = $this->header('CSS', $bucket);

        foreach ($this->sortForBucket($contributions, $bucket) as $contribution) {
            if (ExtensionAssetContribution::TYPE_TAILWIND_SOURCE === $contribution->type()) {
                $lines[] = sprintf('@source "%s";', $this->relativePath('assets/styles/extensions', $contribution->path()));
            }
        }

        foreach ($this->sortForBucket($contributions, $bucket) as $contribution) {
            if (ExtensionAssetContribution::TYPE_CSS === $contribution->type()) {
                $lines[] = sprintf('@import "%s";', $this->relativePath('assets/styles/extensions', $contribution->path()));
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param iterable<ExtensionAssetContribution> $contributions
     */
    public function buildJavaScriptRegistry(iterable $contributions, string $bucket): string
    {
        $lines = $this->header('JavaScript', $bucket);

        foreach ($this->sortForBucket($contributions, $bucket) as $contribution) {
            if (ExtensionAssetContribution::TYPE_JAVASCRIPT === $contribution->type()) {
                $lines[] = sprintf('import "%s";', $this->relativePath('assets/js/extensions', $contribution->path()));
            }
        }

        return implode("\n", $lines)."\n";
    }

    public function bucketForScope(ExtensionScope $scope): string
    {
        return match ($scope) {
            ExtensionScope::FrontendTheme => self::BUCKET_FRONTEND_THEME,
            ExtensionScope::BackendTheme => self::BUCKET_BACKEND_THEME,
            ExtensionScope::SystemTemplate,
            ExtensionScope::Module,
            ExtensionScope::CaptchaProvider,
            ExtensionScope::EditorProvider => self::BUCKET_EXTENSION,
        };
    }

    /**
     * @param iterable<ExtensionAssetContribution> $contributions
     *
     * @return list<ExtensionAssetContribution>
     */
    private function sortForBucket(iterable $contributions, string $bucket): array
    {
        $filtered = [];

        foreach ($contributions as $contribution) {
            if ($bucket === $this->bucketForScope($contribution->scope())) {
                $filtered[] = $contribution;
            }
        }

        usort($filtered, static function (ExtensionAssetContribution $left, ExtensionAssetContribution $right): int {
            return [$left->extension(), $left->path(), $left->type()] <=> [$right->extension(), $right->path(), $right->type()];
        });

        return $filtered;
    }

    /**
     * @return list<string>
     */
    private function header(string $type, string $bucket): array
    {
        return [
            sprintf('/* Generated %s extension asset registry: %s. */', $type, $bucket),
            '/* Extension lifecycle owns this file after activation changes. */',
        ];
    }

    private function relativePath(string $fromDirectory, string $toPath): string
    {
        $fromSegments = $this->pathSegments($fromDirectory);
        $toSegments = $this->pathSegments($toPath);

        while ([] !== $fromSegments && [] !== $toSegments && $fromSegments[0] === $toSegments[0]) {
            array_shift($fromSegments);
            array_shift($toSegments);
        }

        $segments = array_merge(array_fill(0, count($fromSegments), '..'), $toSegments);

        if ([] === $segments) {
            return '.';
        }

        $path = implode('/', $segments);

        return str_starts_with($path, '..') ? $path : './'.$path;
    }

    /**
     * @return list<string>
     */
    private function pathSegments(string $path): array
    {
        return array_values(array_filter(explode('/', str_replace('\\', '/', trim($path, '/'))), static fn (string $segment): bool => '' !== $segment && '.' !== $segment));
    }
}
