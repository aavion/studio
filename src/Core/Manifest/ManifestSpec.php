<?php

declare(strict_types=1);

namespace App\Core\Manifest;

use InvalidArgumentException;

final readonly class ManifestSpec
{
    /**
     * @param list<string> $requiredKeys
     * @param list<string>|null $allowedKeys
     */
    private function __construct(
        private array $requiredKeys = [],
        private ?array $allowedKeys = null,
    ) {
        $this->assertValidKeys($requiredKeys);

        if (null !== $allowedKeys) {
            $this->assertValidKeys($allowedKeys);
        }

        if (null !== $allowedKeys) {
            $unknownRequiredKeys = array_values(array_diff($requiredKeys, $allowedKeys));
            if ([] !== $unknownRequiredKeys) {
                throw new InvalidArgumentException(sprintf(
                    'Required manifest key "%s" is not present in allowed keys.',
                    $unknownRequiredKeys[0],
                ));
            }
        }
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * @param list<string> $keys
     * @param list<string> $requiredKeys
     */
    public static function forNamespace(string $namespace, array $keys, array $requiredKeys = []): self
    {
        self::assertValidKeyPart($namespace, 'namespace');

        $fullKeys = [];
        foreach ($keys as $key) {
            $fullKeys[] = self::joinNamespacedKey($namespace, $key);
        }

        $fullRequiredKeys = [];
        foreach ($requiredKeys as $requiredKey) {
            $fullRequiredKeys[] = self::joinNamespacedKey($namespace, $requiredKey);
        }

        return new self(array_values(array_unique($fullRequiredKeys)), array_values(array_unique($fullKeys)));
    }

    public function require(string $key): self
    {
        $requiredKeys = $this->appendUnique($this->requiredKeys, $key);
        $allowedKeys = null === $this->allowedKeys ? null : $this->appendUnique($this->allowedKeys, $key);

        return new self($requiredKeys, $allowedKeys);
    }

    public function allow(string $key): self
    {
        $allowedKeys = $this->appendUnique($this->allowedKeys ?? $this->requiredKeys, $key);

        return new self($this->requiredKeys, $allowedKeys);
    }

    public function allowOnly(string ...$keys): self
    {
        return new self($this->requiredKeys, array_values(array_unique($keys)));
    }

    /**
     * @return list<string>
     */
    public function requiredKeys(): array
    {
        return $this->requiredKeys;
    }

    /**
     * @return list<string>|null
     */
    public function allowedKeys(): ?array
    {
        return $this->allowedKeys;
    }

    public function allowsUnknownKeys(): bool
    {
        return null === $this->allowedKeys;
    }

    /**
     * @param list<string> $keys
     */
    private function assertValidKeys(array $keys): void
    {
        foreach ($keys as $key) {
            if (!ManifestKey::isValid($key)) {
                throw new InvalidArgumentException(sprintf('Invalid manifest spec key "%s".', $key));
            }
        }
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function appendUnique(array $keys, string $key): array
    {
        $this->assertValidKeys([$key]);

        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
        }

        return $keys;
    }

    private static function joinNamespacedKey(string $namespace, string $key): string
    {
        self::assertValidKeyPart($key, 'key');

        return $namespace.'_'.$key;
    }

    private static function assertValidKeyPart(string $value, string $label): void
    {
        if (!ManifestKey::isValid($value)) {
            throw new InvalidArgumentException(sprintf('Invalid manifest %s "%s".', $label, $value));
        }
    }
}
