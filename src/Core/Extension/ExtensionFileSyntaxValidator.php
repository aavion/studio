<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Lint\CssLinter;
use App\Core\Lint\JavaScriptLinter;
use App\Core\Lint\JsonLinter;
use App\Core\Lint\LinterInterface;
use App\Core\Lint\PhpLinter;
use App\Core\Lint\TwigLinter;
use App\Core\Lint\YamlLinter;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;

final readonly class ExtensionFileSyntaxValidator
{
    public function __construct(
        private PhpLinter $phpLinter = new PhpLinter(),
        private TwigLinter $twigLinter = new TwigLinter(),
        private JsonLinter $jsonLinter = new JsonLinter(),
        private YamlLinter $yamlLinter = new YamlLinter(),
        private CssLinter $cssLinter = new CssLinter(),
        private JavaScriptLinter $javaScriptLinter = new JavaScriptLinter(),
        private ExtensionValidationIssueFactory $issueFactory = new ExtensionValidationIssueFactory(),
    ) {
    }

    /**
     * @return list<Message>
     */
    public function validate(ExtensionCandidate $candidate, ExtensionInspection $inspection, ExtensionSpec $spec): array
    {
        $issues = [];

        if ($spec->lintPhpFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->phpFiles(), $this->phpLinter, ExtensionMessageCode::EXTENSION_PHP_SYNTAX_ERROR, ExtensionMessageKey::EXTENSION_PHP_SYNTAX_ERROR));
        }

        if ($spec->lintTwigFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->twigFiles(), $this->twigLinter, ExtensionMessageCode::EXTENSION_TWIG_SYNTAX_ERROR, ExtensionMessageKey::EXTENSION_TWIG_SYNTAX_ERROR));
        }

        if ($spec->lintJsonFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->jsonFiles(), $this->jsonLinter, ExtensionMessageCode::EXTENSION_JSON_SYNTAX_ERROR, ExtensionMessageKey::EXTENSION_JSON_SYNTAX_ERROR));
        }

        if ($spec->lintYamlFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->yamlFiles(), $this->yamlLinter, ExtensionMessageCode::EXTENSION_YAML_SYNTAX_ERROR, ExtensionMessageKey::EXTENSION_YAML_SYNTAX_ERROR));
        }

        if ($spec->lintCssFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->cssFiles(), $this->cssLinter, ExtensionMessageCode::EXTENSION_CSS_SYNTAX_ERROR, ExtensionMessageKey::EXTENSION_CSS_SYNTAX_ERROR));
        }

        if ($spec->lintJavaScriptFiles()) {
            array_push($issues, ...$this->lintFiles($candidate, $inspection->javaScriptFiles(), $this->javaScriptLinter, ExtensionMessageCode::EXTENSION_JAVASCRIPT_SYNTAX_ERROR, ExtensionMessageKey::EXTENSION_JAVASCRIPT_SYNTAX_ERROR));
        }

        return $issues;
    }

    /**
     * @param list<string> $files
     *
     * @return list<Message>
     */
    private function lintFiles(ExtensionCandidate $candidate, array $files, LinterInterface $linter, string $issueCode, string $translationKey): array
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
                if ($linter instanceof CssLinter
                    && CssLinter::hasStrictParserUnsupportedContext($contents, $lintIssue->line())
                    && $linter->lint(CssLinter::forStrictParser($contents), $file)->isSuccess()
                ) {
                    continue;
                }

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
