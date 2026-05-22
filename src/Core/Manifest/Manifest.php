<?php

declare(strict_types=1);

namespace App\Core\Manifest;

use InvalidArgumentException;

final readonly class Manifest
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(
        private array $values,
    ) {
        foreach ($values as $key => $value) {
            if (!is_string($key) || !ManifestKey::isValid($key)) {
                throw new InvalidArgumentException(sprintf('Invalid manifest key "%s".', (string) $key));
            }

            if (!is_string($value)) {
                throw new InvalidArgumentException(sprintf('Manifest value for "%s" must be a string.', $key));
            }
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }
}
