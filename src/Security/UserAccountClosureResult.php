<?php

declare(strict_types=1);

namespace App\Security;

final readonly class UserAccountClosureResult
{
    /**
     * @param list<string> $errors
     */
    private function __construct(
        private bool $success,
        private array $errors,
    ) {
    }

    public static function success(): self
    {
        return new self(true, []);
    }

    /**
     * @param list<string> $errors
     */
    public static function failed(array $errors): self
    {
        return new self(false, $errors);
    }

    public function successState(): bool
    {
        return $this->success;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
