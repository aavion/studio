<?php

declare(strict_types=1);

namespace App\Core\Package;

final readonly class PackageDependencyParser
{
    private const DEPENDENCY_LIST_PATTERN = '/^\[\s*(?:\[\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]\s*(?:,\s*\[\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]\s*)*)?\]$/';
    private const DEPENDENCY_PAIR_PATTERN = '/\[\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]/';

    /**
     * @return list<array{0: string, 1: string}>|null
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
            $packageName = trim($match[1]);
            $minimumVersion = trim($match[2]);

            if ('' === $packageName || '' === $minimumVersion) {
                return null;
            }

            $dependencies[] = [$packageName, $minimumVersion];
        }

        return $dependencies;
    }
}
