<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Filesystem\FileInventoryScanner;
use App\Core\Lint\CssLinter;
use App\Core\Lint\JavaScriptLinter;
use App\Core\Lint\JsonLinter;
use App\Core\Lint\LinterInterface;
use App\Core\Lint\PhpLinter;
use App\Core\Lint\TwigLinter;
use App\Core\Lint\YamlLinter;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class PackageValidator
{
    public function __construct(
        private readonly PhpLinter $phpLinter = new PhpLinter(),
        private readonly TwigLinter $twigLinter = new TwigLinter(),
        private readonly JsonLinter $jsonLinter = new JsonLinter(),
        private readonly YamlLinter $yamlLinter = new YamlLinter(),
        private readonly CssLinter $cssLinter = new CssLinter(),
        private readonly JavaScriptLinter $javaScriptLinter = new JavaScriptLinter(),
        private readonly FileInventoryScanner $fileInventoryScanner = new FileInventoryScanner(),
        private readonly PackageTemplatePathValidator $templatePathValidator = new PackageTemplatePathValidator(),
        private readonly PackageDependencyParser $dependencyParser = new PackageDependencyParser(),
    ) {
    }

    /**
     * @return WorkflowResult<PackageCandidate>
     */
    public function validate(PackageCandidate $candidate, PackageSpec $spec): WorkflowResult
    {
        $issues = [
            ...$this->validatePackageSlug($candidate),
            ...$this->validateDependencySyntax($candidate),
        ];

        foreach ($spec->requiredFiles() as $path) {
            $absolutePath = $candidate->directory().DIRECTORY_SEPARATOR.$path;
            if (!is_file($absolutePath)) {
                $issues[] = Message::create(
                    MessageCode::PACKAGE_REQUIRED_FILE_MISSING,
                    MessageKey::PACKAGE_REQUIRED_FILE_MISSING,
                    ['%path%' => $absolutePath],
                    context: $this->context($candidate, $path, $absolutePath),
                    level: MessageLevel::Error,
                );
            }
        }

        foreach ($spec->requiredDirectories() as $path) {
            $absolutePath = $candidate->directory().DIRECTORY_SEPARATOR.$path;
            if (!is_dir($absolutePath)) {
                $issues[] = Message::create(
                    MessageCode::PACKAGE_REQUIRED_DIRECTORY_MISSING,
                    MessageKey::PACKAGE_REQUIRED_DIRECTORY_MISSING,
                    ['%path%' => $absolutePath],
                    context: $this->context($candidate, $path, $absolutePath),
                    level: MessageLevel::Error,
                );
            }
        }

        $inspection = $this->inspect($candidate->directory(), $spec->inventoryDepth());

        array_push($issues, ...$this->templatePathValidator->validate($candidate, $inspection->templateFiles()));

        if ($spec->lintPhpFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->phpFiles(), $this->phpLinter, MessageCode::PACKAGE_PHP_SYNTAX_ERROR, MessageKey::PACKAGE_PHP_SYNTAX_ERROR));
        }

        array_push($issues, ...$this->validateSourceNamespaces($candidate, $inspection->sourcePhpFiles()));

        if ($spec->lintTwigFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->twigFiles(), $this->twigLinter, MessageCode::PACKAGE_TWIG_SYNTAX_ERROR, MessageKey::PACKAGE_TWIG_SYNTAX_ERROR));
        }

        if ($spec->lintJsonFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->jsonFiles(), $this->jsonLinter, MessageCode::PACKAGE_JSON_SYNTAX_ERROR, MessageKey::PACKAGE_JSON_SYNTAX_ERROR));
        }

        if ($spec->lintYamlFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->yamlFiles(), $this->yamlLinter, MessageCode::PACKAGE_YAML_SYNTAX_ERROR, MessageKey::PACKAGE_YAML_SYNTAX_ERROR));
        }

        array_push($issues, ...$this->validateTranslationNamespaces($candidate, $inspection->yamlFiles()));

        if ($spec->lintCssFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->cssFiles(), $this->cssLinter, MessageCode::PACKAGE_CSS_SYNTAX_ERROR, MessageKey::PACKAGE_CSS_SYNTAX_ERROR));
        }

        if ($spec->lintJavaScriptFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->javaScriptFiles(), $this->javaScriptLinter, MessageCode::PACKAGE_JAVASCRIPT_SYNTAX_ERROR, MessageKey::PACKAGE_JAVASCRIPT_SYNTAX_ERROR));
        }

        if ([] !== $issues) {
            return WorkflowResult::invalid($issues, [
                'inventory' => $inspection->inventory(),
                'inspection' => $inspection,
            ]);
        }

        $context = [
            'inventory' => $inspection->inventory(),
            'inspection' => $inspection,
        ];

        return WorkflowResult::success($candidate, $context, [
            Message::debug(MessageCode::PACKAGE_VALIDATION_COMPLETED, MessageKey::PACKAGE_VALIDATION_COMPLETED, [
                '%package%' => $candidate->directory(),
            ], [
                'source' => $candidate->source()->name(),
                'package' => $candidate->directory(),
                'inventory_count' => count($inspection->inventory()),
            ]),
        ]);
    }

    /**
     * @return list<Message>
     */
    private function validatePackageSlug(PackageCandidate $candidate): array
    {
        if ('package' !== $candidate->source()->name()) {
            return [];
        }

        $slug = trim((string) $candidate->manifest()->get('PACKAGE_SLUG', ''));

        if ('' === $slug) {
            return [
                Message::create(
                    MessageCode::MANIFEST_MISSING_REQUIRED_KEY,
                    MessageKey::MANIFEST_MISSING_REQUIRED_KEY,
                    ['%key%' => 'PACKAGE_SLUG'],
                    ['source' => $candidate->source()->name(), 'path' => $candidate->manifestPath(), 'key' => 'PACKAGE_SLUG'],
                    MessageLevel::Error,
                ),
            ];
        }

        if (!PackageManifestSpec::isValidSlug($slug)) {
            return [
                Message::create(
                    MessageCode::PACKAGE_IDENTIFIER_INVALID,
                    MessageKey::PACKAGE_IDENTIFIER_INVALID,
                    ['%identifier%' => $slug],
                    ['source' => $candidate->source()->name(), 'path' => $candidate->manifestPath(), 'key' => 'PACKAGE_SLUG', 'slug' => $slug],
                    MessageLevel::Error,
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<Message>
     */
    private function validateDependencySyntax(PackageCandidate $candidate): array
    {
        $value = $candidate->manifest()->get('PACKAGE_DEPENDENCIES');

        if (null !== $this->dependencyParser->parse($value)) {
            return [];
        }

        return [
            Message::create(
                MessageCode::PACKAGE_DEPENDENCY_INVALID,
                MessageKey::PACKAGE_DEPENDENCY_INVALID,
                ['%package%' => trim((string) $candidate->manifest()->get('PACKAGE_SLUG', ''))],
                [
                    'source' => $candidate->source()->name(),
                    'path' => $candidate->manifestPath(),
                    'key' => 'PACKAGE_DEPENDENCIES',
                    'value' => $value,
                ],
                MessageLevel::Error,
            ),
        ];
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

    private function inspect(string $directory, int $depth): PackageInspection
    {
        $inventory = $this->fileInventoryScanner->scan($directory, $depth);
        $assetFiles = $inventory->filesWhere(static fn (string $path): bool => str_starts_with($path, 'assets/'));
        $cssFiles = $inventory->filesWhere(static fn (string $path): bool => str_ends_with($path, '.css'));
        $javaScriptFiles = $inventory->filesWhere(static fn (string $path): bool => str_ends_with($path, '.js') || str_ends_with($path, '.mjs'));

        return new PackageInspection(
            $inventory->entries(),
            $inventory->filesWhere(static fn (string $path): bool => str_starts_with($path, 'templates/') && str_ends_with($path, '.twig')),
            $assetFiles,
            $inventory->filesWhere(static fn (string $path): bool => str_ends_with($path, '.php')),
            $inventory->filesWhere(static fn (string $path): bool => str_starts_with($path, 'src/') && str_ends_with($path, '.php')),
            $inventory->filesWhere(static fn (string $path): bool => str_ends_with($path, '.twig')),
            $inventory->filesWhere(static fn (string $path): bool => str_ends_with($path, '.json')),
            $inventory->filesWhere(static fn (string $path): bool => str_ends_with($path, '.yaml') || str_ends_with($path, '.yml')),
            $cssFiles,
            $javaScriptFiles,
            array_values(array_filter($assetFiles, static fn (string $path): bool => !in_array($path, [...$cssFiles, ...$javaScriptFiles], true))),
        );
    }

    /**
     * @param list<string> $files
     *
     * @return list<Message>
     */
    private function lintFiles(PackageCandidate $candidate, array $files, LinterInterface $linter, string $issueCode, string $translationKey): array
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
                $issues[] = Message::create(
                    $issueCode,
                    $translationKey,
                    ['%path%' => $path],
                    context: $this->lintContext($candidate, $file, $path, $lintIssue->context()),
                    level: MessageLevel::Error,
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

    private function unreadableFileIssue(PackageCandidate $candidate, string $file, string $path): Message
    {
        return Message::create(
            MessageCode::PACKAGE_FILE_UNREADABLE,
            MessageKey::PACKAGE_FILE_UNREADABLE,
            ['%path%' => $path],
            context: $this->lintContext($candidate, $file, $path),
            level: MessageLevel::Error,
        );
    }

    /**
     * @param list<string> $files
     *
     * @return list<Message>
     */
    private function validateSourceNamespaces(PackageCandidate $candidate, array $files): array
    {
        $expectedNamespace = trim((string) ($candidate->manifest()->get('PACKAGE_NAMESPACE') ?? ''));

        if ('' === $expectedNamespace || [] === $files) {
            return [];
        }

        if (!$this->isValidPhpNamespace($expectedNamespace)) {
            return [$this->invalidNamespaceIssue($candidate, 'PACKAGE_NAMESPACE', 'PACKAGE_NAMESPACE', $expectedNamespace, $expectedNamespace)];
        }

        $issues = [];

        foreach ($files as $file) {
            $path = $candidate->directory().DIRECTORY_SEPARATOR.$file;
            $contents = file_get_contents($path);

            if (false === $contents) {
                $issues[] = $this->unreadableFileIssue($candidate, $file, $path);
                continue;
            }

            $namespace = $this->declaredPhpNamespace($contents);

            if (!$this->isPackageNamespace($namespace, $expectedNamespace)) {
                $issues[] = $this->invalidNamespaceIssue($candidate, $file, $path, $namespace ?? '', $expectedNamespace);
            }
        }

        return $issues;
    }

    private function isValidPhpNamespace(string $namespace): bool
    {
        return 1 === preg_match('/^[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*$/', $namespace);
    }

    private function isPackageNamespace(?string $namespace, string $expectedNamespace): bool
    {
        return null !== $namespace
            && ($namespace === $expectedNamespace || str_starts_with($namespace, $expectedNamespace.'\\'));
    }

    private function declaredPhpNamespace(string $contents): ?string
    {
        $tokens = token_get_all($contents);
        $namespace = '';
        $collect = false;

        foreach ($tokens as $token) {
            if (is_array($token) && T_NAMESPACE === $token[0]) {
                $collect = true;
                continue;
            }

            if (!$collect) {
                continue;
            }

            if (is_string($token) && (';' === $token || '{' === $token)) {
                break;
            }

            if (is_array($token) && T_WHITESPACE === $token[0]) {
                continue;
            }

            if (is_array($token) && in_array($token[0], $this->namespaceTokenIds(), true)) {
                $namespace .= $token[1];
                continue;
            }

            if (is_string($token) && '\\' === $token) {
                $namespace .= $token;
            }
        }

        $namespace = trim($namespace, '\\');

        return '' === $namespace ? null : $namespace;
    }

    /**
     * @return list<int>
     */
    private function namespaceTokenIds(): array
    {
        $ids = [T_STRING, T_NS_SEPARATOR];

        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $constant) {
            if (defined($constant)) {
                $ids[] = constant($constant);
            }
        }

        return $ids;
    }

    private function invalidNamespaceIssue(
        PackageCandidate $candidate,
        string $file,
        string $path,
        string $namespace,
        string $expectedNamespace,
    ): Message {
        return Message::create(
            MessageCode::PACKAGE_PHP_NAMESPACE_INVALID,
            MessageKey::PACKAGE_PHP_NAMESPACE_INVALID,
            ['%path%' => $path, '%expected_namespace%' => $expectedNamespace],
            context: $this->lintContext($candidate, $file, $path, [
                'namespace' => $namespace,
                'expected_namespace' => $expectedNamespace,
            ]),
            level: MessageLevel::Error,
        );
    }

    /**
     * @param list<string> $files
     *
     * @return list<Message>
     */
    private function validateTranslationNamespaces(PackageCandidate $candidate, array $files): array
    {
        $packageName = $this->translationPackageName($candidate);
        $translationFiles = array_values(array_filter(
            $files,
            static fn (string $file): bool => 1 === preg_match('#^languages/[a-z][a-z0-9]*(?:[_-][A-Za-z0-9]+)*/[^/]+\.yaml$#', $file),
        ));
        $issues = [];

        if ([] === $translationFiles) {
            return [];
        }

        if ([] === array_filter($translationFiles, static fn (string $file): bool => str_starts_with($file, 'languages/en/'))) {
            $issues[] = Message::create(
                MessageCode::PACKAGE_TRANSLATION_ENGLISH_MISSING,
                MessageKey::PACKAGE_TRANSLATION_ENGLISH_MISSING,
                ['%package%' => $packageName],
                context: $this->lintContext($candidate, 'languages/en', $candidate->directory().DIRECTORY_SEPARATOR.'languages/en', [
                    'package' => $packageName,
                ]),
                level: MessageLevel::Error,
            );
        }

        foreach ($translationFiles as $file) {
            $path = $candidate->directory().DIRECTORY_SEPARATOR.$file;

            try {
                $data = Yaml::parseFile($path);
            } catch (Throwable) {
                continue;
            }

            if (
                !is_array($data)
                || array_keys($data) !== ['pkg']
                || !isset($data['pkg'])
                || !is_array($data['pkg'])
                || array_keys($data['pkg']) !== [$packageName]
            ) {
                $issues[] = Message::create(
                    MessageCode::PACKAGE_TRANSLATION_NAMESPACE_INVALID,
                    MessageKey::PACKAGE_TRANSLATION_NAMESPACE_INVALID,
                    ['%path%' => $path, '%package%' => $packageName],
                    context: $this->lintContext($candidate, $file, $path, [
                        'package' => $packageName,
                        'expected_prefix' => 'pkg.'.$packageName,
                    ]),
                    level: MessageLevel::Error,
                );
            }
        }

        return $issues;
    }

    private function translationPackageName(PackageCandidate $candidate): string
    {
        $slug = trim((string) $candidate->manifest()->get('PACKAGE_SLUG', ''));

        return PackageManifestSpec::isValidSlug($slug) ? $slug : basename($candidate->directory());
    }
}
