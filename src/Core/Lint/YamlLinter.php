<?php

declare(strict_types=1);

namespace App\Core\Lint;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlLinter implements LinterInterface
{
    public function lint(string $contents, ?string $path = null): LintResult
    {
        try {
            Yaml::parse($contents);
        } catch (ParseException $error) {
            return LintResult::invalid([
                LintIssue::create(
                    'lint.yaml_syntax_error',
                    'YAML has a syntax error.',
                    $error->getParsedLine(),
                    details: ['error' => $error->getMessage(), 'path' => $path],
                ),
            ]);
        }

        return LintResult::success();
    }
}
