<?php

declare(strict_types=1);

namespace App\Core\Package;

final class PackageAssetRegistryBuilder
{
    public const BUCKET_EXTENSION = 'extension';
    public const BUCKET_FRONTEND_THEME = 'frontend-theme';
    public const BUCKET_BACKEND_THEME = 'backend-theme';

    /**
     * @param iterable<PackageAssetContribution> $contributions
     */
    public function buildCssRegistry(iterable $contributions, string $bucket): string
    {
        $lines = $this->header('CSS', $bucket);

        foreach ($this->sortForBucket($contributions, $bucket) as $contribution) {
            if (PackageAssetContribution::TYPE_TAILWIND_SOURCE === $contribution->type()) {
                $lines[] = sprintf('@source "%s";', $this->relativePath('assets/styles/packages', $contribution->path()));
            }
        }

        foreach ($this->sortForBucket($contributions, $bucket) as $contribution) {
            if (PackageAssetContribution::TYPE_CSS === $contribution->type()) {
                $lines[] = sprintf('@import "%s";', $this->relativePath('assets/styles/packages', $contribution->path()));
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param iterable<PackageAssetContribution> $contributions
     */
    public function buildJavaScriptRegistry(iterable $contributions, string $bucket): string
    {
        $lines = $this->header('JavaScript', $bucket);

        foreach ($this->sortForBucket($contributions, $bucket) as $contribution) {
            if (PackageAssetContribution::TYPE_JAVASCRIPT === $contribution->type()) {
                $lines[] = sprintf('import "%s";', $this->relativePath('assets/js/packages', $contribution->path()));
            }
        }

        return implode("\n", $lines)."\n";
    }

    public function bucketForScope(PackageScope $scope): string
    {
        return match ($scope) {
            PackageScope::FrontendTheme => self::BUCKET_FRONTEND_THEME,
            PackageScope::BackendTheme => self::BUCKET_BACKEND_THEME,
            PackageScope::SystemTemplate,
            PackageScope::Module,
            PackageScope::CaptchaProvider,
            PackageScope::EditorProvider => self::BUCKET_EXTENSION,
        };
    }

    /**
     * @param iterable<PackageAssetContribution> $contributions
     *
     * @return list<PackageAssetContribution>
     */
    private function sortForBucket(iterable $contributions, string $bucket): array
    {
        $filtered = [];

        foreach ($contributions as $contribution) {
            if ($bucket === $this->bucketForScope($contribution->scope())) {
                $filtered[] = $contribution;
            }
        }

        usort($filtered, static function (PackageAssetContribution $left, PackageAssetContribution $right): int {
            return [$left->package(), $left->path(), $left->type()] <=> [$right->package(), $right->path(), $right->type()];
        });

        return $filtered;
    }

    /**
     * @return list<string>
     */
    private function header(string $type, string $bucket): array
    {
        return [
            sprintf('/* Generated %s package asset registry: %s. */', $type, $bucket),
            '/* Package lifecycle owns this file after activation changes. */',
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
