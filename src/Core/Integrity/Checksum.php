<?php

declare(strict_types=1);

namespace App\Core\Integrity;

use InvalidArgumentException;

final readonly class Checksum
{
    public function __construct(
        private string $algorithm,
        private string $value,
    ) {
        if ('' === trim($algorithm)) {
            throw new InvalidArgumentException('Checksum algorithm must not be empty.');
        }

        if ('' === trim($value)) {
            throw new InvalidArgumentException('Checksum value must not be empty.');
        }
    }

    public static function sha256(string $value): self
    {
        return new self('sha256', $value);
    }

    public function algorithm(): string
    {
        return $this->algorithm;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->algorithm === $other->algorithm
            && hash_equals($this->value, $other->value);
    }

    public function toIntegrityString(): string
    {
        return $this->algorithm.':'.$this->value;
    }
}
