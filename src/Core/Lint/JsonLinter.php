<?php

declare(strict_types=1);

namespace App\Core\Lint;

use App\Core\Lint\LintMessageCode;
use App\Core\Lint\LintMessageKey;
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
                    LintMessageCode::LINT_JSON_SYNTAX_ERROR,
                    LintMessageKey::LINT_JSON_SYNTAX_ERROR,
                    details: ['error' => $error->getMessage(), 'path' => $path],
                ),
            ]);
        }

        return LintResult::success();
    }
}
