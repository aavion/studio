<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Lint\CssLinter;
use App\Core\Lint\JavaScriptLinter;
use App\Core\Lint\JsonLinter;
use App\Core\Lint\LinterInterface;
use App\Core\Lint\PhpLinter;
use App\Core\Lint\TwigLinter;
use App\Core\Lint\YamlLinter;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;

final class PackageValidator
{
    public function __construct(
        private readonly PhpLinter $phpLinter = new PhpLinter(),
        private readonly TwigLinter $twigLinter = new TwigLinter(),
        private readonly JsonLinter $jsonLinter = new JsonLinter(),
        private readonly YamlLinter $yamlLinter = new YamlLinter(),
        private readonly CssLinter $cssLinter = new CssLinter(),
        private readonly JavaScriptLinter $javaScriptLinter = new JavaScriptLinter(),
    ) {
    }

    /**
     * @return OperationResult<PackageCandidate>
     */
    public function validate(PackageCandidate $candidate, PackageSpec $spec): OperationResult
    {
        $issues = [];

        foreach ($spec->requiredFiles() as $path) {
            $absolutePath = $candidate->directory().DIRECTORY_SEPARATOR.$path;
            if (!is_file($absolutePath)) {
                $issues[] = OperationIssue::create(
                    'package.required_file_missing',
                    'Required package file is missing.',
                    $this->context($candidate, $path, $absolutePath),
                );
            }
        }

        foreach ($spec->requiredDirectories() as $path) {
            $absolutePath = $candidate->directory().DIRECTORY_SEPARATOR.$path;
            if (!is_dir($absolutePath)) {
                $issues[] = OperationIssue::create(
                    'package.required_directory_missing',
                    'Required package directory is missing.',
                    $this->context($candidate, $path, $absolutePath),
                );
            }
        }

        $inspection = $this->inspect($candidate->directory(), $spec->inventoryDepth());

        if ($spec->lintPhpFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->phpFiles(), $this->phpLinter, 'package.php_syntax_error', 'Package PHP file has a syntax error.'));
        }

        if ($spec->lintTwigFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->twigFiles(), $this->twigLinter, 'package.twig_syntax_error', 'Package Twig file has a syntax error.'));
        }

        if ($spec->lintJsonFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->jsonFiles(), $this->jsonLinter, 'package.json_syntax_error', 'Package JSON file has a syntax error.'));
        }

        if ($spec->lintYamlFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->yamlFiles(), $this->yamlLinter, 'package.yaml_syntax_error', 'Package YAML file has a syntax error.'));
        }

        if ($spec->lintCssFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->cssFiles(), $this->cssLinter, 'package.css_syntax_error', 'Package CSS file has a syntax error.'));
        }

        if ($spec->lintJavaScriptFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->javaScriptFiles(), $this->javaScriptLinter, 'package.javascript_syntax_error', 'Package JavaScript file has a syntax error.'));
        }

        if ([] !== $issues) {
            return OperationResult::invalid($issues, [
                'inventory' => $inspection->inventory(),
                'inspection' => $inspection,
            ]);
        }

        return OperationResult::success($candidate, [
            'inventory' => $inspection->inventory(),
            'inspection' => $inspection,
        ]);
    }

    /**
     * @return array{source: string, package: string, requirement: string, path: string}
     */
    private function context(PackageCandidate $candidate, string $requirement, string $path): array
    {
        return [
            'source' => $candidate->source()->name(),
            'package' => $candidate->directory(),
            'requirement' => $requirement,
            'path' => $path,
        ];
    }

    /**
     * @return list<string>
     */
    private function inspect(string $directory, int $depth): PackageInspection
    {
        $inventory = [];
        $this->collectInventory($directory, $directory, $depth, $inventory);
        sort($inventory);

        return new PackageInspection(
            $inventory,
            $this->filterFiles($inventory, static fn (string $path): bool => str_starts_with($path, 'templates/') && str_ends_with($path, '.twig')),
            $this->filterFiles($inventory, static fn (string $path): bool => str_starts_with($path, 'assets/')),
            $this->filterFiles($inventory, static fn (string $path): bool => str_ends_with($path, '.php')),
            $this->filterFiles($inventory, static fn (string $path): bool => str_starts_with($path, 'src/') && str_ends_with($path, '.php')),
            $this->filterFiles($inventory, static fn (string $path): bool => str_ends_with($path, '.twig')),
            $this->filterFiles($inventory, static fn (string $path): bool => str_ends_with($path, '.json')),
            $this->filterFiles($inventory, static fn (string $path): bool => str_ends_with($path, '.yaml') || str_ends_with($path, '.yml')),
            $this->filterFiles($inventory, static fn (string $path): bool => str_ends_with($path, '.css')),
            $this->filterFiles($inventory, static fn (string $path): bool => str_ends_with($path, '.js') || str_ends_with($path, '.mjs')),
        );
    }

    /**
     * @param list<string> $entries
     */
    private function collectInventory(string $root, string $directory, int $remainingDepth, array &$entries): void
    {
        if ($remainingDepth < 0) {
            return;
        }

        $items = scandir($directory);
        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;
            $relativePath = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
            $entries[] = is_dir($path) ? $relativePath.'/' : $relativePath;

            if (is_dir($path)) {
                $this->collectInventory($root, $path, $remainingDepth - 1, $entries);
            }
        }
    }

    /**
     * @param list<string> $inventory
     *
     * @return list<string>
     */
    private function filterFiles(array $inventory, callable $filter): array
    {
        return array_values(array_filter(
            $inventory,
            static fn (string $path): bool => !str_ends_with($path, '/') && $filter($path),
        ));
    }

    /**
     * @param list<string> $files
     *
     * @return list<OperationIssue>
     */
    private function lintFiles(PackageCandidate $candidate, array $files, LinterInterface $linter, string $issueCode, string $issueMessage): array
    {
        $issues = [];

        foreach ($files as $file) {
            $path = $candidate->directory().DIRECTORY_SEPARATOR.$file;
            $contents = file_get_contents($path);

            if (false === $contents) {
                $issues[] = $this->unreadableFileIssue($candidate, $file, $path);
                continue;
            }

            $lintResult = $linter->lint($contents, $file);

            foreach ($lintResult->issues() as $lintIssue) {
                $issues[] = OperationIssue::create(
                    $issueCode,
                    $issueMessage,
                    $this->lintContext($candidate, $file, $path, $lintIssue->context()),
                );
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function lintContext(PackageCandidate $candidate, string $file, string $path, array $extra = []): array
    {
        return [
            'source' => $candidate->source()->name(),
            'package' => $candidate->directory(),
            ...$extra,
            'path' => $path,
            'file' => $file,
        ];
    }

    private function unreadableFileIssue(PackageCandidate $candidate, string $file, string $path): OperationIssue
    {
        return OperationIssue::create(
            'package.file_unreadable',
            'Package file could not be read.',
            $this->lintContext($candidate, $file, $path),
        );
    }
}
