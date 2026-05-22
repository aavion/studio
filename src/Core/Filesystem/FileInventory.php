<?php

declare(strict_types=1);

namespace App\Core\Filesystem;

use InvalidArgumentException;

final readonly class FileInventory
{
    /**
     * @param list<string> $entries
     */
    public function __construct(
        private array $entries,
    ) {
        foreach ($entries as $entry) {
            if (!is_string($entry) || '' === $entry) {
                throw new InvalidArgumentException('File inventory entries must be non-empty strings.');
            }
        }
    }

    /**
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<string>
     */
    public function files(): array
    {
        return array_values(array_filter($this->entries, static fn (string $path): bool => !str_ends_with($path, '/')));
    }

    /**
     * @return list<string>
     */
    public function directories(): array
    {
        return array_values(array_filter($this->entries, static fn (string $path): bool => str_ends_with($path, '/')));
    }

    /**
     * @return list<string>
     */
    public function filesWhere(callable $filter): array
    {
        return array_values(array_filter($this->files(), $filter));
    }
}
