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
    public static function hasStrictParserUnsupportedContext(string $contents, ?int $line): bool
    {
        if (null === $line) {
            return false;
        }

        return self::isStrictParserUnsupportedLine($contents, $line - 1)
            || self::isStrictParserUnsupportedLine($contents, $line)
            || self::isStrictParserUnsupportedLine($contents, $line + 1);
    }

    public static function isTailwindDirectiveLine(string $contents, ?int $line): bool
    {
        return self::isStrictParserUnsupportedLine($contents, $line);
    }

    public static function isStrictParserUnsupportedLine(string $contents, ?int $line): bool
    {
        if (null === $line) {
            return false;
        }

        $lines = preg_split('/\R/', $contents) ?: [];
        $text = trim((string) ($lines[$line - 1] ?? ''));

        return self::isTailwindDirectiveText($text)
            || self::isUnsupportedGroupAtRuleText($text)
            || self::containsEmptyCustomPropertyFallback($text);
    }

    public static function withoutTailwindDirectiveLines(string $contents): string
    {
        return self::forStrictParser($contents);
    }

    public static function forStrictParser(string $contents): string
    {
        $lines = preg_split('/\R/', $contents) ?: [];
        $depth = 0;
        $unwrapGroupClosingDepths = [];

        foreach ($lines as $index => $line) {
            $text = trim((string) $line);

            if (self::isTailwindDirectiveText($text)) {
                $lines[$index] = '/* Tailwind directive omitted for strict CSS parsing. */';
                $depth += self::braceDelta((string) $line);

                continue;
            }

            if (self::isUnsupportedGroupAtRuleText($text)) {
                $unwrapGroupClosingDepths[] = $depth;
                $lines[$index] = '/* CSS group at-rule omitted for strict CSS parsing. */';
                $depth += self::braceDelta((string) $line);

                continue;
            }

            $delta = self::braceDelta((string) $line);
            if ('}' === $text && [] !== $unwrapGroupClosingDepths && $depth - 1 === end($unwrapGroupClosingDepths)) {
                array_pop($unwrapGroupClosingDepths);
                $lines[$index] = '/* CSS group at-rule closing brace omitted for strict CSS parsing. */';
            }

            $depth += $delta;
        }

        return preg_replace('/var\((--[A-Za-z0-9_-]+),\)/', 'var($1, initial)', implode("\n", $lines))
            ?? implode("\n", $lines);
    }

    private static function isTailwindDirectiveText(string $text): bool
    {
        return str_starts_with($text, '@apply ')
            || str_starts_with($text, '@theme ')
            || str_starts_with($text, '@custom-variant ')
            || str_starts_with($text, '@source ');
    }

    private static function isUnsupportedGroupAtRuleText(string $text): bool
    {
        return (str_starts_with($text, '@supports ')
                || str_starts_with($text, '@container ')
                || str_starts_with($text, '@media '))
            && str_contains($text, '{');
    }

    private static function containsEmptyCustomPropertyFallback(string $text): bool
    {
        return 1 === preg_match('/var\(--[A-Za-z0-9_-]+,\)/', $text);
    }

    private static function braceDelta(string $line): int
    {
        return substr_count($line, '{') - substr_count($line, '}');
    }

    public function lint(string $contents, ?string $path = null): LintResult
    {
        if (self::isEffectivelyEmpty($contents)) {
            return LintResult::success();
        }

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

    private static function isEffectivelyEmpty(string $contents): bool
    {
        $withoutComments = preg_replace('/\/\*.*?\*\//s', '', $contents);

        return '' === trim((string) $withoutComments);
    }
}
