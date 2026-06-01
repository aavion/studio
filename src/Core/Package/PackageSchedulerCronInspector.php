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
            $arguments[] = $this->cronArgument($body, 4);
        }

        foreach ($this->callBodiesAt($contents, $this->newDefinitionCallOffsets($contents)) as $body) {
            $arguments[] = $this->cronArgument($body, 6);
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
        $names = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            if (T_USE !== $tokens[$index]['type']) {
                continue;
            }

            $next = $this->nextSignificantToken($tokens, $index);
            if (null === $next || in_array($tokens[$next]['type'], [T_FUNCTION, T_CONST], true)) {
                continue;
            }

            $statement = '';
            for ($cursor = $index + 1; $cursor < $count; ++$cursor) {
                if (';' === $tokens[$cursor]['text']) {
                    break;
                }

                if (in_array($tokens[$cursor]['type'], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $statement .= $tokens[$cursor]['text'];
            }

            foreach ($this->importedDefinitionNames($statement) as $name) {
                $names[$name] = true;
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

        return in_array(ltrim($token['text'], '\\'), ['App\\Scheduler\\SchedulerTaskDefinition'], true);
    }

    /**
     * @return list<string>
     */
    private function importedDefinitionNames(string $statement): array
    {
        $statement = trim(preg_replace('/\s+/', ' ', $statement) ?? $statement);
        if ('' === $statement) {
            return [];
        }

        if (str_contains($statement, '{')) {
            return $this->groupedImportedDefinitionNames($statement);
        }

        $names = [];
        foreach (explode(',', $statement) as $import) {
            $name = $this->importedDefinitionName($import);
            if (null !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function groupedImportedDefinitionNames(string $statement): array
    {
        if (1 !== preg_match('/^(.+?)\{(.+)\}$/s', $statement, $match)) {
            return [];
        }

        $prefix = trim($match[1], " \t\n\r\0\x0B\\");
        if ('App\\Scheduler' !== $prefix) {
            return [];
        }

        $names = [];
        foreach (explode(',', $match[2]) as $import) {
            $name = $this->importedDefinitionName('App\\Scheduler\\'.trim($import));
            if (null !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function importedDefinitionName(string $import): ?string
    {
        $import = trim($import);
        if ('' === $import) {
            return null;
        }

        $parts = preg_split('/\s+as\s+/i', $import);
        $class = ltrim(trim($parts[0] ?? ''), '\\');
        if ('App\\Scheduler\\SchedulerTaskDefinition' !== $class) {
            return null;
        }

        $alias = trim($parts[1] ?? '');

        return '' !== $alias ? $alias : 'SchedulerTaskDefinition';
    }

    private function cronArgument(string $contents, int $position): ?string
    {
        $positional = [];
        foreach ($this->callArguments($contents) as $argument) {
            if (($argument['name'] ?? null) === 'defaultCronExpression') {
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
