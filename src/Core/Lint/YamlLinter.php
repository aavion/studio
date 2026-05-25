<?php

declare(strict_types=1);

namespace App\Core\Lint;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
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
                    MessageCode::LINT_YAML_SYNTAX_ERROR,
                    MessageKey::LINT_YAML_SYNTAX_ERROR,
                    $error->getParsedLine(),
                    details: ['error' => $error->getMessage(), 'path' => $path],
                ),
            ]);
        }

        return LintResult::success();
    }
}
