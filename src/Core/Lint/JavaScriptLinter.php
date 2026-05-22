<?php

declare(strict_types=1);

namespace App\Core\Lint;

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
                    'lint.javascript_syntax_error',
                    'JavaScript has a syntax error.',
                    $line,
                    $column,
                    ['error' => $error->getMessage(), 'path' => $path],
                ),
            ]);
        }

        return LintResult::success();
    }
}
