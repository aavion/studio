<?php

declare(strict_types=1);

namespace App\Backend;

use Throwable;

final readonly class PackageDependencyLabelParser
{
    /**
     * @return list<string>
     */
    public function parse(?string $raw): array
    {
        if (null === $raw || '' === trim($raw) || '[]' === trim($raw)) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [$raw];
        }

        if (!is_array($decoded)) {
            return [$raw];
        }

        $dependencies = [];

        foreach ($decoded as $dependency) {
            if (is_scalar($dependency)) {
                $dependencies[] = (string) $dependency;

                continue;
            }

            if (is_array($dependency)) {
                $dependencies[] = implode(' ', array_filter(array_map(
                    static fn (mixed $part): ?string => is_scalar($part) ? (string) $part : null,
                    $dependency,
                )));
            }
        }

        return array_values(array_filter($dependencies, static fn (string $dependency): bool => '' !== trim($dependency)));
    }
}
