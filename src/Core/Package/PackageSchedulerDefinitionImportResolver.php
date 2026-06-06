<?php

declare(strict_types=1);

namespace App\Core\Package;

final readonly class PackageSchedulerDefinitionImportResolver
{
    /**
     * @param list<array{type: int|string, text: string, offset: int}> $tokens
     *
     * @return array<string, true>
     */
    public function definitionNames(array $tokens): array
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

            foreach ($this->importedDefinitionNames($this->importStatement($tokens, $index)) as $name) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * @param array{type: int|string, text: string, offset: int} $token
     * @param array<string, true> $definitionNames
     */
    public function isDefinitionToken(array $token, array $definitionNames): bool
    {
        if (isset($definitionNames[$token['text']])) {
            return true;
        }

        return in_array(ltrim($token['text'], '\\'), ['App\\Scheduler\\SchedulerTaskDefinition'], true);
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
     * @param list<array{type: int|string, text: string, offset: int}> $tokens
     */
    private function importStatement(array $tokens, int $index): string
    {
        $statement = '';

        for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; ++$cursor) {
            if (';' === $tokens[$cursor]['text']) {
                break;
            }

            if (!in_array($tokens[$cursor]['type'], [T_COMMENT, T_DOC_COMMENT], true)) {
                $statement .= $tokens[$cursor]['text'];
            }
        }

        return $statement;
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
        if (!in_array($prefix, ['App', 'App\\Scheduler'], true)) {
            return [];
        }

        $names = [];
        foreach (explode(',', $match[2]) as $import) {
            $base = 'App' === $prefix ? 'App\\' : 'App\\Scheduler\\';
            $name = $this->importedDefinitionName($base.trim($import));
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
        $alias = trim($parts[1] ?? '');

        if ('App\\Scheduler\\SchedulerTaskDefinition' === $class) {
            return '' !== $alias ? $alias : 'SchedulerTaskDefinition';
        }

        if ('App\\Scheduler' === $class) {
            return ('' !== $alias ? $alias : 'Scheduler').'\\SchedulerTaskDefinition';
        }

        if ('App' === $class) {
            return ('' !== $alias ? $alias : 'App').'\\Scheduler\\SchedulerTaskDefinition';
        }

        return null;
    }
}
