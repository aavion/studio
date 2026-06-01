<?php

declare(strict_types=1);

namespace App\Core\Package;

final readonly class PackageSchedulerCronInspector
{
    /**
     * @return list<string>
     */
    public function expressions(string $contents): array
    {
        return array_values(array_filter(
            $this->cronArguments($contents),
            static fn (?string $expression): bool => null !== $expression,
        ));
    }

    /**
     * @return list<string|null>
     */
    public function cronArguments(string $contents): array
    {
        if (!str_contains($contents, 'SchedulerTaskDefinition')) {
            return [];
        }

        $arguments = [];

        foreach ($this->callBodiesAt($contents, $this->staticCommandCallOffsets($contents)) as $body) {
            if (null !== ($namedExpression = $this->namedStringArgument($body, 'defaultCronExpression'))) {
                $arguments[] = $namedExpression;
                continue;
            }

            $arguments[] = $this->literalStringArgument($this->positionalArguments($body)[4] ?? null);
        }

        foreach ($this->callBodiesAt($contents, $this->newDefinitionCallOffsets($contents)) as $body) {
            if (null !== ($namedExpression = $this->namedStringArgument($body, 'defaultCronExpression'))) {
                $arguments[] = $namedExpression;
                continue;
            }

            $arguments[] = $this->literalStringArgument($this->positionalArguments($body)[6] ?? null);
        }

        return array_values(array_unique($arguments));
    }

    /**
     * @return list<int>
     */
    private function staticCommandCallOffsets(string $contents): array
    {
        $tokens = $this->tokensWithOffsets($contents);
        $definitionNames = $this->definitionNames($tokens);
        $offsets = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!$this->isDefinitionToken($token, $definitionNames)) {
                continue;
            }

            $next = $this->nextSignificantToken($tokens, $index);
            $method = null !== $next ? $this->nextSignificantToken($tokens, $next) : null;

            if (
                null === $next
                || null === $method
                || T_DOUBLE_COLON !== $tokens[$next]['type']
                || !$this->tokenEquals($tokens[$method], 'command')
            ) {
                continue;
            }

            $offsets[] = $tokens[$method]['offset'] + strlen($tokens[$method]['text']);
        }

        return $offsets;
    }

    /**
     * @return list<int>
     */
    private function newDefinitionCallOffsets(string $contents): array
    {
        $tokens = $this->tokensWithOffsets($contents);
        $definitionNames = $this->definitionNames($tokens);
        $offsets = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            if (T_NEW !== $tokens[$index]['type']) {
                continue;
            }

            $class = $this->nextSignificantToken($tokens, $index);
            if (null === $class || !$this->isDefinitionToken($tokens[$class], $definitionNames)) {
                continue;
            }

            $offsets[] = $tokens[$class]['offset'] + strlen($tokens[$class]['text']);
        }

        return $offsets;
    }

    /**
     * @return list<string>
     */
    private function callBodiesAt(string $contents, array $offsets): array
    {
        $bodies = [];

        foreach ($offsets as $offset) {
            $open = strpos($contents, '(', $offset);
            if (false === $open) {
                continue;
            }

            $depth = 0;
            $quote = null;
            $escaped = false;
            $length = strlen($contents);

            for ($index = $open; $index < $length; ++$index) {
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

                if ('(' === $char) {
                    ++$depth;
                    continue;
                }

                if (')' !== $char) {
                    continue;
                }

                --$depth;
                if (0 === $depth) {
                    $bodies[] = substr($contents, $open + 1, $index - $open - 1);
                    continue 2;
                }
            }
        }

        return $bodies;
    }

    /**
     * @return list<array{type: int|string, text: string, offset: int}>
     */
    private function tokensWithOffsets(string $contents): array
    {
        $tokens = [];
        $offset = 0;

        foreach (token_get_all($contents) as $token) {
            if (is_array($token)) {
                $text = $token[1];
                $tokens[] = ['type' => $token[0], 'text' => $text, 'offset' => $offset];
                $offset += strlen($text);
                continue;
            }

            $tokens[] = ['type' => $token, 'text' => $token, 'offset' => $offset];
            $offset += strlen($token);
        }

        return $tokens;
    }

    /**
     * @param list<array{type: int|string, text: string, offset: int}> $tokens
     */
    private function nextSignificantToken(array $tokens, int $index): ?int
    {
        for ($next = $index + 1, $count = count($tokens); $next < $count; ++$next) {
            if (in_array($tokens[$next]['type'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $next;
        }

        return null;
    }

    /**
     * @param array{type: int|string, text: string, offset: int} $token
     */
    private function tokenEquals(array $token, string $text): bool
    {
        return $token['text'] === $text;
    }

    /**
     * @param array{type: int|string, text: string, offset: int} $token
     */
    private function tokenEndsWith(array $token, string $text): bool
    {
        return $token['text'] === $text || str_ends_with($token['text'], '\\'.$text);
    }

    /**
     * @param list<array{type: int|string, text: string, offset: int}> $tokens
     *
     * @return array<string, true>
     */
    private function definitionNames(array $tokens): array
    {
        $names = ['SchedulerTaskDefinition' => true];
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            if (T_USE !== $tokens[$index]['type']) {
                continue;
            }

            for ($cursor = $index + 1; $cursor < $count; ++$cursor) {
                if (in_array($tokens[$cursor]['type'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if (';' === $tokens[$cursor]['text']) {
                    break;
                }

                if (!$this->tokenEndsWith($tokens[$cursor], 'SchedulerTaskDefinition')) {
                    continue;
                }

                $names['SchedulerTaskDefinition'] = true;
                for ($aliasCursor = $cursor + 1; $aliasCursor < $count; ++$aliasCursor) {
                    if (in_array($tokens[$aliasCursor]['type'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }

                    if (T_AS === $tokens[$aliasCursor]['type']) {
                        $aliasIndex = $this->nextSignificantToken($tokens, $aliasCursor);
                        if (null !== $aliasIndex && T_STRING === $tokens[$aliasIndex]['type']) {
                            $names[$tokens[$aliasIndex]['text']] = true;
                        }
                    }

                    break;
                }
            }
        }

        return $names;
    }

    /**
     * @param array{type: int|string, text: string, offset: int} $token
     * @param array<string, true> $definitionNames
     */
    private function isDefinitionToken(array $token, array $definitionNames): bool
    {
        if (isset($definitionNames[$token['text']])) {
            return true;
        }

        return $this->tokenEndsWith($token, 'SchedulerTaskDefinition');
    }

    private function namedStringArgument(string $contents, string $name): ?string
    {
        if (1 !== preg_match('/\b'.preg_quote($name, '/').'\s*:\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s', $contents, $match)) {
            return null;
        }

        return stripcslashes($match[2]);
    }

    /**
     * @return list<string>
     */
    private function positionalArguments(string $contents): array
    {
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

            $arguments[] = trim(substr($contents, $start, $index - $start));
            $start = $index + 1;
        }

        $last = trim(substr($contents, $start));
        if ('' !== $last) {
            $arguments[] = $last;
        }

        return array_values(array_filter(
            $arguments,
            static fn (string $argument): bool => 1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\s*:(?!:)/', $argument),
        ));
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
