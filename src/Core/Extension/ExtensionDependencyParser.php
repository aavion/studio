<?php

declare(strict_types=1);

namespace App\Core\Extension;

final readonly class ExtensionDependencyParser
{
    private const DEPENDENCY_LIST_PATTERN = '/^\[\s*(?:\[\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]\s*(?:,\s*\[\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]\s*)*)?\]$/';
    private const DEPENDENCY_PAIR_PATTERN = '/\[\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]/';
    private const VERSION_CONSTRAINT_PATTERN = '/^(?:(>=|=)\s*)?([A-Za-z0-9][A-Za-z0-9._+~:-]*)$/';

    /**
     * @return list<array{0: string, 1: string, 2: string}>|null
     */
    public function parse(mixed $value): ?array
    {
        if (!is_string($value)) {
            return [];
        }

        $value = trim($value);

        if ('' === $value || '[]' === $value) {
            return [];
        }

        if (1 !== preg_match(self::DEPENDENCY_LIST_PATTERN, $value)) {
            return null;
        }

        preg_match_all(self::DEPENDENCY_PAIR_PATTERN, $value, $matches, PREG_SET_ORDER);

        $dependencies = [];

        foreach ($matches as $match) {
            $extensionName = trim($match[1]);
            $constraint = $this->parseConstraint(trim($match[2]));

            if ('' === $extensionName || null === $constraint) {
                return null;
            }

            $dependencies[] = [$extensionName, $constraint['version'], $constraint['operator']];
        }

        return $dependencies;
    }

    /**
     * @return array{operator: string, version: string}|null
     */
    private function parseConstraint(string $value): ?array
    {
        if ('' === $value || 1 !== preg_match(self::VERSION_CONSTRAINT_PATTERN, $value, $matches)) {
            return null;
        }

        return [
            'operator' => $matches[1] ?? '',
            'version' => $matches[2],
        ];
    }
}
