<?php

declare(strict_types=1);

namespace App\Core\Package;

use InvalidArgumentException;

final readonly class PackageSpec
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
    ) {
        $this->assertRelativePaths($requiredFiles);
        $this->assertRelativePaths($requiredDirectories);

        if ($inventoryDepth < 0) {
            throw new InvalidArgumentException('Package inventory depth must not be negative.');
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
        );
    }

    public function requireDirectory(string $path): self
    {
        return new self(
            $this->requiredFiles,
            $this->appendUnique($this->requiredDirectories, $path),
            $this->inventoryDepth,
            $this->lintChecks,
        );
    }

    public function withInventoryDepth(int $depth): self
    {
        return new self($this->requiredFiles, $this->requiredDirectories, $depth, $this->lintChecks);
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

    /**
     * @param list<string> $paths
     */
    private function assertRelativePaths(array $paths): void
    {
        foreach ($paths as $path) {
            if ('' === trim($path)) {
                throw new InvalidArgumentException('Package requirement path must not be empty.');
            }

            if (str_starts_with($path, '/') || str_contains($path, '..')) {
                throw new InvalidArgumentException(sprintf('Package requirement path "%s" must be relative and stay inside the package.', $path));
            }
        }
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function appendUnique(array $paths, string $path): array
    {
        $path = trim($path, '/');
        $this->assertRelativePaths([$path]);

        if (!in_array($path, $paths, true)) {
            $paths[] = $path;
        }

        return $paths;
    }

    private function withLintCheck(string $check, bool $enabled): self
    {
        $lintChecks = $this->lintChecks;
        $lintChecks[$check] = $enabled;

        return new self($this->requiredFiles, $this->requiredDirectories, $this->inventoryDepth, $lintChecks);
    }
}
