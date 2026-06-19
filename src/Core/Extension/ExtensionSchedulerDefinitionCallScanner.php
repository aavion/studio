<?php

declare(strict_types=1);

namespace App\Core\Extension;

final readonly class ExtensionSchedulerDefinitionCallScanner
{
    public function __construct(
        private ExtensionSchedulerDefinitionImportResolver $importResolver = new ExtensionSchedulerDefinitionImportResolver(),
    ) {
    }

    /**
     * @return list<array{body: string, cron_argument_position: int}>
     */
    public function definitionCalls(string $contents): array
    {
        $calls = [];

        foreach ($this->callBodiesAt($contents, $this->staticCommandCallOffsets($contents)) as $body) {
            $calls[] = ['body' => $body, 'cron_argument_position' => 4];
        }

        foreach ($this->callBodiesAt($contents, $this->newDefinitionCallOffsets($contents)) as $body) {
            $calls[] = ['body' => $body, 'cron_argument_position' => 6];
        }

        return $calls;
    }

    /**
     * @return list<int>
     */
    private function staticCommandCallOffsets(string $contents): array
    {
        $tokens = $this->tokensWithOffsets($contents);
        $definitionNames = $this->importResolver->definitionNames($tokens);
        $offsets = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!$this->importResolver->isDefinitionToken($token, $definitionNames)) {
                continue;
            }

            $next = $this->nextSignificantToken($tokens, $index);
            $method = null !== $next ? $this->nextSignificantToken($tokens, $next) : null;

            if (
                null !== $next
                && null !== $method
                && T_DOUBLE_COLON === $tokens[$next]['type']
                && $this->tokenEquals($tokens[$method], 'command')
            ) {
                $offsets[] = $tokens[$method]['offset'] + strlen($tokens[$method]['text']);
            }
        }

        return $offsets;
    }

    /**
     * @return list<int>
     */
    private function newDefinitionCallOffsets(string $contents): array
    {
        $tokens = $this->tokensWithOffsets($contents);
        $definitionNames = $this->importResolver->definitionNames($tokens);
        $offsets = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            if (T_NEW !== $tokens[$index]['type']) {
                continue;
            }

            $class = $this->nextSignificantToken($tokens, $index);
            if (null !== $class && $this->importResolver->isDefinitionToken($tokens[$class], $definitionNames)) {
                $offsets[] = $tokens[$class]['offset'] + strlen($tokens[$class]['text']);
            }
        }

        return $offsets;
    }

    /**
     * @param list<int> $offsets
     *
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

            $body = $this->callBodyAt($contents, $open);
            if (null !== $body) {
                $bodies[] = $body;
            }
        }

        return $bodies;
    }

    private function callBodyAt(string $contents, int $open): ?string
    {
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
                return substr($contents, $open + 1, $index - $open - 1);
            }
        }

        return null;
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
            if (!in_array($tokens[$next]['type'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $next;
            }
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

}
