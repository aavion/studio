<?php

declare(strict_types=1);

namespace App\Core\Lint;

use JsonException;

final class JsonLinter implements LinterInterface
{
    public function lint(string $contents, ?string $path = null): LintResult
    {
        try {
            json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            return LintResult::invalid([
                LintIssue::create(
                    'lint.json_syntax_error',
                    'JSON has a syntax error.',
                    details: ['error' => $error->getMessage(), 'path' => $path],
                ),
            ]);
        }

        return LintResult::success();
    }
}
