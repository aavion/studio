<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use InvalidArgumentException;

final readonly class ExtensionSpec
{
    private const LINT_PHP = 'php';
    private const LINT_TWIG = 'twig';
    private const LINT_JSON = 'json';
    private const LINT_YAML = 'yaml';
    private const LINT_CSS = 'css';
    private const LINT_JAVASCRIPT = 'javascript';

    /**
     * @param list<string> $requiredFiles
     * @param list<string> $requiredDirectories
     * @param array<string, bool> $lintChecks
     */
    private function __construct(
        private array $requiredFiles = [],
        private array $requiredDirectories = [],
        private int $inventoryDepth = 2,
        private array $lintChecks = [],
        private bool $directorySlugMatchRequired = true,
    ) {
        $this->assertRelativePaths($requiredFiles);
        $this->assertRelativePaths($requiredDirectories);

        if ($inventoryDepth < 0) {
            throw new InvalidArgumentException('Extension inventory depth must not be negative.');
        }
    }

    public static function create(): self
    {
        return new self();
    }

    public function requireFile(string $path): self
    {
        return new self(
            $this->appendUnique($this->requiredFiles, $path),
            $this->requiredDirectories,
            $this->inventoryDepth,
            $this->lintChecks,
            $this->directorySlugMatchRequired,
        );
    }

    public function requireDirectory(string $path): self
    {
        return new self(
            $this->requiredFiles,
            $this->appendUnique($this->requiredDirectories, $path),
            $this->inventoryDepth,
            $this->lintChecks,
            $this->directorySlugMatchRequired,
        );
    }

    public function withInventoryDepth(int $depth): self
    {
        return new self($this->requiredFiles, $this->requiredDirectories, $depth, $this->lintChecks, $this->directorySlugMatchRequired);
    }

    public function withDirectorySlugMatch(bool $required = true): self
    {
        return new self($this->requiredFiles, $this->requiredDirectories, $this->inventoryDepth, $this->lintChecks, $required);
    }

    public function withLintingChecks(bool $enabled = true): self
    {
        $spec = $this;

        foreach ([self::LINT_PHP, self::LINT_TWIG, self::LINT_JSON, self::LINT_YAML, self::LINT_CSS, self::LINT_JAVASCRIPT] as $check) {
            $spec = $spec->withLintCheck($check, $enabled);
        }

        return $spec;
    }

    public function withPhpLinting(bool $enabled = true): self
    {
        return $this->withLintCheck(self::LINT_PHP, $enabled);
    }

    public function withTwigLinting(bool $enabled = true): self
    {
        return $this->withLintCheck(self::LINT_TWIG, $enabled);
    }

    public function withJsonLinting(bool $enabled = true): self
    {
        return $this->withLintCheck(self::LINT_JSON, $enabled);
    }

    public function withYamlLinting(bool $enabled = true): self
    {
        return $this->withLintCheck(self::LINT_YAML, $enabled);
    }

    public function withCssLinting(bool $enabled = true): self
    {
        return $this->withLintCheck(self::LINT_CSS, $enabled);
    }

    public function withJavaScriptLinting(bool $enabled = true): self
    {
        return $this->withLintCheck(self::LINT_JAVASCRIPT, $enabled);
    }

    /**
     * @return list<string>
     */
    public function requiredFiles(): array
    {
        return $this->requiredFiles;
    }

    /**
     * @return list<string>
     */
    public function requiredDirectories(): array
    {
        return $this->requiredDirectories;
    }

    public function inventoryDepth(): int
    {
        return $this->inventoryDepth;
    }

    public function lintPhpFiles(): bool
    {
        return $this->lintChecks[self::LINT_PHP] ?? false;
    }

    public function lintTwigFiles(): bool
    {
        return $this->lintChecks[self::LINT_TWIG] ?? false;
    }

    public function lintJsonFiles(): bool
    {
        return $this->lintChecks[self::LINT_JSON] ?? false;
    }

    public function lintYamlFiles(): bool
    {
        return $this->lintChecks[self::LINT_YAML] ?? false;
    }

    public function lintCssFiles(): bool
    {
        return $this->lintChecks[self::LINT_CSS] ?? false;
    }

    public function lintJavaScriptFiles(): bool
    {
        return $this->lintChecks[self::LINT_JAVASCRIPT] ?? false;
    }

    public function directorySlugMatchRequired(): bool
    {
        return $this->directorySlugMatchRequired;
    }

    /**
     * @param list<string> $paths
     */
    private function assertRelativePaths(array $paths): void
    {
        foreach ($paths as $path) {
            $this->normalizeRequirementPath($path);
        }
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function appendUnique(array $paths, string $path): array
    {
        $path = $this->normalizeRequirementPath($path);

        if (!in_array($path, $paths, true)) {
            $paths[] = $path;
        }

        return $paths;
    }

    private function normalizeRequirementPath(string $path): string
    {
        try {
            return (new PathGuard())->relativePath($path);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(sprintf('Extension requirement path "%s" must be relative and stay inside the extension.', $path));
        }
    }

    private function withLintCheck(string $check, bool $enabled): self
    {
        $lintChecks = $this->lintChecks;
        $lintChecks[$check] = $enabled;

        return new self($this->requiredFiles, $this->requiredDirectories, $this->inventoryDepth, $lintChecks, $this->directorySlugMatchRequired);
    }
}
