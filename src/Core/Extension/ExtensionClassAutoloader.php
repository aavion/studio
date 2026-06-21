<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use App\Core\Message\MessageException;
use App\Entity\Extension;

final class ExtensionClassAutoloader
{
    /**
     * @var array<string, string>
     */
    private array $prefixes = [];

    private bool $registered = false;

    public function __construct(
        private readonly string $projectDir,
        private readonly PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    public function register(Extension $extension): void
    {
        if (ExtensionStatus::Active !== $extension->status()) {
            return;
        }

        $namespace = $this->namespace($extension);
        if (null === $namespace) {
            return;
        }

        $sourceDirectory = $this->sourceDirectory($extension);
        if (null === $sourceDirectory || !is_dir($sourceDirectory)) {
            return;
        }

        $prefix = $namespace.'\\';
        foreach ($this->prefixes as $existingPrefix => $existingSourceDirectory) {
            if (!$this->prefixesOverlap($prefix, $existingPrefix)) {
                continue;
            }

            if ($prefix === $existingPrefix && $existingSourceDirectory === $sourceDirectory) {
                continue;
            }

            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_PHP_NAMESPACE_INVALID, [
                '%path%' => $extension->path().'/src',
                '%expected_namespace%' => $namespace,
            ], [
                'extension' => $extension->extensionName(),
                'namespace' => $namespace,
                'existing_namespace' => rtrim($existingPrefix, '\\'),
                'existing_source' => $this->projectRelativePath($existingSourceDirectory),
                'source' => $this->projectRelativePath($sourceDirectory),
            ]);
        }

        $this->prefixes[$prefix] = $sourceDirectory;

        if (!$this->registered) {
            spl_autoload_register($this->load(...));
            $this->registered = true;
        }
    }

    public function reset(): void
    {
        $this->prefixes = [];
    }

    private function load(string $class): void
    {
        $prefixes = $this->prefixes;
        uksort($prefixes, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($prefixes as $prefix => $sourceDirectory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relativeClass = substr($class, strlen($prefix));
            if ('' === $relativeClass || str_contains($relativeClass, "\0")) {
                return;
            }

            $path = $sourceDirectory.'/'.str_replace('\\', '/', $relativeClass).'.php';
            if (is_file($path)) {
                require $path;
            }

            return;
        }
    }

    private function prefixesOverlap(string $left, string $right): bool
    {
        return str_starts_with($left, $right) || str_starts_with($right, $left);
    }

    private function namespace(Extension $extension): ?string
    {
        $metadata = $extension->metadata();
        $manifest = $metadata['manifest'] ?? [];
        $namespace = is_array($manifest) ? trim((string) ($manifest['EXTENSION_NAMESPACE'] ?? '')) : '';

        if ('' === $namespace) {
            return null;
        }

        if (1 !== preg_match('/^[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*$/', $namespace)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_PHP_NAMESPACE_INVALID, [
                '%path%' => 'EXTENSION_NAMESPACE',
                '%expected_namespace%' => $namespace,
            ], [
                'extension' => $extension->extensionName(),
                'namespace' => $namespace,
                'expected_namespace' => $namespace,
            ]);
        }

        return $namespace;
    }

    private function sourceDirectory(Extension $extension): ?string
    {
        try {
            return rtrim($this->projectDir, '/').'/'.$this->pathGuard->relativePath($extension->path().'/src');
        } catch (\Throwable) {
            return null;
        }
    }

    private function projectRelativePath(string $path): string
    {
        $projectDir = rtrim($this->projectDir, '/').'/';

        return str_starts_with($path, $projectDir) ? substr($path, strlen($projectDir)) : $path;
    }
}
