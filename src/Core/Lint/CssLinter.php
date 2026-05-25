<?php

declare(strict_types=1);

namespace App\Core\Lint;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\Parsing\SourceException;
use Sabberworm\CSS\Settings;

final class CssLinter implements LinterInterface
{
    public function lint(string $contents, ?string $path = null): LintResult
    {
        try {
            (new Parser($contents, Settings::create()->beStrict()))->parse();
        } catch (SourceException $error) {
            return LintResult::invalid([
                LintIssue::create(
                    MessageCode::LINT_CSS_SYNTAX_ERROR,
                    MessageKey::LINT_CSS_SYNTAX_ERROR,
                    $error->getLineNumber(),
                    $error->getColumnNumber(),
                    ['error' => $error->getMessage(), 'path' => $path],
                ),
            ]);
        }

        return LintResult::success();
    }
}
