<?php

declare(strict_types=1);

namespace App\Core\Lint;

use App\Core\Lint\LintMessageCode;
use App\Core\Lint\LintMessageKey;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\Parsing\SourceException;
use Sabberworm\CSS\Settings;

final class CssLinter implements LinterInterface
{
    public static function isTailwindDirectiveLine(string $contents, ?int $line): bool
    {
        if (null === $line) {
            return false;
        }

        $lines = preg_split('/\R/', $contents) ?: [];
        $text = trim((string) ($lines[$line - 1] ?? ''));

        return self::isTailwindDirectiveText($text);
    }

    public static function withoutTailwindDirectiveLines(string $contents): string
    {
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach ($lines as $index => $line) {
            if (self::isTailwindDirectiveText(trim((string) $line))) {
                $lines[$index] = '/* Tailwind directive omitted for strict CSS parsing. */';
            }
        }

        return implode("\n", $lines);
    }

    private static function isTailwindDirectiveText(string $text): bool
    {
        return str_starts_with($text, '@apply ')
            || str_starts_with($text, '@theme ')
            || str_starts_with($text, '@custom-variant ')
            || str_starts_with($text, '@source ');
    }

    public function lint(string $contents, ?string $path = null): LintResult
    {
        try {
            (new Parser($contents, Settings::create()->beStrict()))->parse();
        } catch (SourceException $error) {
            return LintResult::invalid([
                LintIssue::create(
                    LintMessageCode::LINT_CSS_SYNTAX_ERROR,
                    LintMessageKey::LINT_CSS_SYNTAX_ERROR,
                    $error->getLineNumber(),
                    $error->getColumnNumber(),
                    ['error' => $error->getMessage(), 'path' => $path],
                ),
            ]);
        }

        return LintResult::success();
    }
}
