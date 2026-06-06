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
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;

final readonly class PackageFileSyntaxValidator
{
    public function __construct(
        private PhpLinter $phpLinter = new PhpLinter(),
        private TwigLinter $twigLinter = new TwigLinter(),
        private JsonLinter $jsonLinter = new JsonLinter(),
        private YamlLinter $yamlLinter = new YamlLinter(),
        private CssLinter $cssLinter = new CssLinter(),
        private JavaScriptLinter $javaScriptLinter = new JavaScriptLinter(),
        private PackageValidationIssueFactory $issueFactory = new PackageValidationIssueFactory(),
    ) {
    }

    /**
     * @return list<Message>
     */
    public function validate(PackageCandidate $candidate, PackageInspection $inspection, PackageSpec $spec): array
    {
        $issues = [];

        if ($spec->lintPhpFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->phpFiles(), $this->phpLinter, MessageCode::PACKAGE_PHP_SYNTAX_ERROR, MessageKey::PACKAGE_PHP_SYNTAX_ERROR));
        }

        if ($spec->lintTwigFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->twigFiles(), $this->twigLinter, MessageCode::PACKAGE_TWIG_SYNTAX_ERROR, MessageKey::PACKAGE_TWIG_SYNTAX_ERROR));
        }

        if ($spec->lintJsonFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->jsonFiles(), $this->jsonLinter, MessageCode::PACKAGE_JSON_SYNTAX_ERROR, MessageKey::PACKAGE_JSON_SYNTAX_ERROR));
        }

        if ($spec->lintYamlFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->yamlFiles(), $this->yamlLinter, MessageCode::PACKAGE_YAML_SYNTAX_ERROR, MessageKey::PACKAGE_YAML_SYNTAX_ERROR));
        }

        if ($spec->lintCssFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->cssFiles(), $this->cssLinter, MessageCode::PACKAGE_CSS_SYNTAX_ERROR, MessageKey::PACKAGE_CSS_SYNTAX_ERROR));
        }

        if ($spec->lintJavaScriptFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->javaScriptFiles(), $this->javaScriptLinter, MessageCode::PACKAGE_JAVASCRIPT_SYNTAX_ERROR, MessageKey::PACKAGE_JAVASCRIPT_SYNTAX_ERROR));
        }

        return $issues;
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
                $issues[] = $this->issueFactory->unreadableFile($candidate, $file, $path);
                continue;
            }

            $lintResult = $linter->lint($contents, $file);

            foreach ($lintResult->issues() as $lintIssue) {
                $issues[] = Message::create(
                    $issueCode,
                    $translationKey,
                    ['%path%' => $path],
                    context: $this->issueFactory->fileContext($candidate, $file, $path, $lintIssue->context()),
                    level: MessageLevel::Error,
                );
            }
        }

        return $issues;
    }
}
