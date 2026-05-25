<?php

declare(strict_types=1);

namespace App\Core\Package;

final class PackageAssetPathRewriter
{
    public function rewriteCss(
        string $contents,
        string $sourceFile,
        string $sourceAssetRoot,
        string $mirrorAssetRoot,
        string $builtCssDirectory = 'assets/styles',
    ): string {
        if ($this->isVendorPath($sourceFile)) {
            return $contents;
        }

        return preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/',
            function (array $matches) use ($sourceFile, $sourceAssetRoot, $mirrorAssetRoot, $builtCssDirectory): string {
                $reference = trim($matches[2]);

                if (!$this->isRelativeReference($reference)) {
                    return $matches[0];
                }

                $rewritten = $this->mirrorReference(
                    $reference,
                    $sourceFile,
                    $sourceAssetRoot,
                    $mirrorAssetRoot,
                    $builtCssDirectory,
                );

                return sprintf('url("%s")', $rewritten);
            },
            $contents,
        ) ?? $contents;
    }

    public function rewriteJavaScript(
        string $contents,
        string $sourceFile,
        string $sourceAssetRoot,
        string $mirrorFile,
        string $mirrorAssetRoot,
    ): string {
        if ($this->isVendorPath($sourceFile)) {
            return $contents;
        }

        $staticImportPattern = '/(?P<prefix>\b(?:import|export)\s+(?:[^\'";]*?\s+from\s+)?)(?P<quote>[\'"])(?P<reference>\.{1,2}\/[^\'"]+)(?P=quote)/';
        $dynamicImportPattern = '/(?P<prefix>\bimport\s*\(\s*)(?P<quote>[\'"])(?P<reference>\.{1,2}\/[^\'"]+)(?P=quote)(?P<suffix>\s*\))/';

        $contents = preg_replace_callback(
            $staticImportPattern,
            function (array $matches) use ($sourceFile, $sourceAssetRoot, $mirrorFile, $mirrorAssetRoot): string {
                return $matches['prefix']
                    .$matches['quote']
                    .$this->rewriteJavaScriptReference($matches['reference'], $sourceFile, $sourceAssetRoot, $mirrorFile, $mirrorAssetRoot)
                    .$matches['quote'];
            },
            $contents,
        ) ?? $contents;

        return preg_replace_callback(
            $dynamicImportPattern,
            function (array $matches) use ($sourceFile, $sourceAssetRoot, $mirrorFile, $mirrorAssetRoot): string {
                return $matches['prefix']
                    .$matches['quote']
                    .$this->rewriteJavaScriptReference($matches['reference'], $sourceFile, $sourceAssetRoot, $mirrorFile, $mirrorAssetRoot)
                    .$matches['quote']
                    .$matches['suffix'];
            },
            $contents,
        ) ?? $contents;
    }

    private function rewriteJavaScriptReference(
        string $reference,
        string $sourceFile,
        string $sourceAssetRoot,
        string $mirrorFile,
        string $mirrorAssetRoot,
    ): string {
        if (!$this->isRelativeReference($reference)) {
            return $reference;
        }

        return $this->mirrorReference(
            $reference,
            $sourceFile,
            $sourceAssetRoot,
            $mirrorAssetRoot,
            dirname($mirrorFile),
        );
    }

    private function mirrorReference(
        string $reference,
        string $sourceFile,
        string $sourceAssetRoot,
        string $mirrorAssetRoot,
        string $targetDirectory,
    ): string {
        [$path, $suffix] = $this->splitReference($reference);
        $sourceReference = $this->normalizePath(dirname($sourceFile).'/'.$path);
        $sourceRoot = rtrim($this->normalizePath($sourceAssetRoot), '/');

        if ($sourceReference === $sourceRoot || !str_starts_with($sourceReference, $sourceRoot.'/')) {
            return $reference;
        }

        $relativeToRoot = substr($sourceReference, strlen($sourceRoot) + 1);
        $mirrorReference = rtrim($this->normalizePath($mirrorAssetRoot), '/').'/'.$relativeToRoot;

        return $this->relativePath($targetDirectory, $mirrorReference).$suffix;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitReference(string $reference): array
    {
        $position = strcspn($reference, '?#');

        return [substr($reference, 0, $position), substr($reference, $position)];
    }

    private function isRelativeReference(string $reference): bool
    {
        if ('' === $reference || str_starts_with($reference, '#') || str_starts_with($reference, '/')) {
            return false;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference)) {
            return false;
        }

        return str_starts_with($reference, './') || str_starts_with($reference, '../');
    }

    private function isVendorPath(string $path): bool
    {
        $path = '/'.$this->normalizePath($path).'/';

        return str_contains($path, '/vendor/')
            || str_contains($path, '/vendors/')
            || str_contains($path, '/node_modules/');
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
        return array_values(array_filter(explode('/', $this->normalizePath($path)), static fn (string $segment): bool => '' !== $segment && '.' !== $segment));
    }

    private function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', trim($path, '/'))) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}
