<?php

declare(strict_types=1);

namespace App\Core\Package;

final readonly class PackagePhpCallArgumentParser
{
    public function literalArgumentAt(string $contents, int $position, string $namedArgument): ?string
    {
        $positional = [];

        foreach ($this->callArguments($contents) as $argument) {
            if (($argument['name'] ?? null) === $namedArgument) {
                return $this->literalStringArgument($argument['value']);
            }

            if (!isset($argument['name'])) {
                $positional[] = $argument['value'];
            }
        }

        return $this->literalStringArgument($positional[$position] ?? null);
    }

    /**
     * @return list<array{name?: string, value: string}>
     */
    private function callArguments(string $contents): array
    {
        $contents = $this->withoutPhpComments($contents);
        $arguments = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($contents);

        for ($index = 0; $index < $length; ++$index) {
            $char = $contents[$index];

            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ('\\' === $char) {
                    $escaped = true;
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ('\'' === $char || '"' === $char) {
                $quote = $char;
                continue;
            }

            if (in_array($char, ['(', '[', '{'], true)) {
                ++$depth;
                continue;
            }

            if (in_array($char, [')', ']', '}'], true)) {
                $depth = max(0, $depth - 1);
                continue;
            }

            if (',' !== $char || 0 !== $depth) {
                continue;
            }

            $this->appendCallArgument($arguments, substr($contents, $start, $index - $start));
            $start = $index + 1;
        }

        $last = trim(substr($contents, $start));
        if ('' !== $last) {
            $this->appendCallArgument($arguments, $last);
        }

        return $arguments;
    }

    private function withoutPhpComments(string $contents): string
    {
        $stripped = '';

        foreach (token_get_all('<?php '.$contents) as $token) {
            if (is_array($token)) {
                if (T_OPEN_TAG === $token[0] || in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $stripped .= $token[1];
                continue;
            }

            $stripped .= $token;
        }

        return $stripped;
    }

    /**
     * @param list<array{name?: string, value: string}> $arguments
     */
    private function appendCallArgument(array &$arguments, string $argument): void
    {
        $argument = trim($argument);
        if ('' === $argument) {
            return;
        }

        if (1 === preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*:(?!:)\s*(.+)$/s', $argument, $match)) {
            $arguments[] = ['name' => $match[1], 'value' => trim($match[2])];

            return;
        }

        $arguments[] = ['value' => $argument];
    }

    private function literalStringArgument(?string $contents): ?string
    {
        if (null === $contents) {
            return null;
        }

        $tokens = array_values(array_filter(
            token_get_all('<?php '.$contents),
            static fn (array|string $token): bool => !is_array($token) || !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE], true),
        ));

        if (1 !== count($tokens) || !is_array($tokens[0]) || T_CONSTANT_ENCAPSED_STRING !== $tokens[0][0]) {
            return null;
        }

        $literal = $tokens[0][1];

        return stripcslashes(substr($literal, 1, -1));
    }
}
