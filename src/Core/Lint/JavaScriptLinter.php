<?php

declare(strict_types=1);

namespace App\Core\Lint;

use App\Core\Lint\LintMessageCode;
use App\Core\Lint\LintMessageKey;
use Peast\Peast;
use Peast\Syntax\EncodingException;
use Peast\Syntax\Exception as SyntaxException;

final class JavaScriptLinter implements LinterInterface
{
    public function lint(string $contents, ?string $path = null): LintResult
    {
        try {
            Peast::latest($contents, ['sourceType' => Peast::SOURCE_TYPE_MODULE])->parse();
        } catch (SyntaxException|EncodingException $error) {
            $line = null;
            $column = null;

            if ($error instanceof SyntaxException) {
                $line = $error->getPosition()->getLine();
                $column = $error->getPosition()->getColumn();
            }

            return LintResult::invalid([
                LintIssue::create(
                    LintMessageCode::LINT_JAVASCRIPT_SYNTAX_ERROR,
                    LintMessageKey::LINT_JAVASCRIPT_SYNTAX_ERROR,
                    $line,
                    $column,
                    ['error' => $error->getMessage(), 'path' => $path],
                ),
            ]);
        }

        return LintResult::success();
    }
}
