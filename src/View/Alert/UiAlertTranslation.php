<?php

declare(strict_types=1);

namespace App\View\Alert;

final readonly class UiAlertTranslation
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        private string $level,
        private string $translationKey,
        private array $parameters = [],
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function success(string $translationKey, array $parameters = []): self
    {
        return new self('success', $translationKey, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function warning(string $translationKey, array $parameters = []): self
    {
        return new self('warning', $translationKey, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function error(string $translationKey, array $parameters = []): self
    {
        return new self('error', $translationKey, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function forLevel(string $level, string $translationKey, array $parameters = []): self
    {
        return new self($level, $translationKey, $parameters);
    }

    public function level(): string
    {
        return $this->level;
    }

    public function translationKey(): string
    {
        return $this->translationKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }
}
