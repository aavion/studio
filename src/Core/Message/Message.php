<?php

declare(strict_types=1);

namespace App\Core\Message;

use InvalidArgumentException;

final readonly class Message
{
    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    private function __construct(
        private string $code,
        private string $translationKey,
        private array $parameters = [],
        private array $context = [],
    ) {
        if (!$this->isValidCode($code)) {
            throw new InvalidArgumentException(sprintf('Invalid message code "%s".', $code));
        }

        if (!$this->isValidTranslationKey($translationKey)) {
            throw new InvalidArgumentException(sprintf('Invalid message translation key "%s".', $translationKey));
        }

        $this->assertParameterKeys($parameters);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    public static function create(string $code, string $translationKey, array $parameters = [], array $context = []): self
    {
        return new self($code, $translationKey, $parameters, $context);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    public static function success(string $translationKey, array $parameters = [], array $context = []): self
    {
        return new self(MessageCode::SUCCESS, $translationKey, $parameters, $context);
    }

    public function code(): string
    {
        return $this->code;
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

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): self
    {
        return new self($this->code, $this->translationKey, $this->parameters, [
            ...$this->context,
            ...$context,
        ]);
    }

    /**
     * @return array{code: string, translation_key: string, parameters: array<string, mixed>, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'translation_key' => $this->translationKey,
            'parameters' => $this->parameters,
            'context' => $this->context,
        ];
    }

    private function isValidCode(string $code): bool
    {
        return 1 === preg_match('/^(?:[A-Z][A-Z0-9_]*|[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+)$/', $code);
    }

    private function isValidTranslationKey(string $translationKey): bool
    {
        return 1 === preg_match('/^message(?:\.[a-z][a-z0-9_]*)+$/', $translationKey);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function assertParameterKeys(array $parameters): void
    {
        foreach (array_keys($parameters) as $key) {
            if (!is_string($key) || 1 !== preg_match('/^%[a-z][a-z0-9_]*%$/', $key)) {
                throw new InvalidArgumentException(sprintf('Invalid message parameter key "%s".', (string) $key));
            }
        }
    }
}
