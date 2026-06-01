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
        if (!str_contains($contents, 'SchedulerTaskDefinition')) {
            return [];
        }

        $expressions = [];

        foreach ($this->callBodies($contents, 'SchedulerTaskDefinition::command') as $body) {
            $strings = $this->literalStrings($body);
            if (isset($strings[4])) {
                $expressions[] = $strings[4];
            }
            if (null !== ($namedExpression = $this->namedStringArgument($body, 'defaultCronExpression'))) {
                $expressions[] = $namedExpression;
            }
        }

        foreach ($this->callBodies($contents, 'new SchedulerTaskDefinition') as $body) {
            $strings = $this->literalStrings($body);
            if (isset($strings[5])) {
                $expressions[] = $strings[5];
            }
            if (null !== ($namedExpression = $this->namedStringArgument($body, 'defaultCronExpression'))) {
                $expressions[] = $namedExpression;
            }
        }

        return array_values(array_unique($expressions));
    }

    /**
     * @return list<string>
     */
    private function callBodies(string $contents, string $needle): array
    {
        $bodies = [];
        $offset = 0;

        while (false !== ($position = strpos($contents, $needle, $offset))) {
            $open = strpos($contents, '(', $position + strlen($needle));
            if (false === $open) {
                break;
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
                    $offset = $index + 1;
                    continue 2;
                }
            }

            $offset = $open + 1;
        }

        return $bodies;
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
    private function literalStrings(string $contents): array
    {
        $strings = [];

        foreach (token_get_all('<?php '.$contents) as $token) {
            if (!is_array($token) || T_CONSTANT_ENCAPSED_STRING !== $token[0]) {
                continue;
            }

            $literal = $token[1];
            $strings[] = stripcslashes(substr($literal, 1, -1));
        }

        return $strings;
    }
}
