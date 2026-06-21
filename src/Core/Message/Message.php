<?php

declare(strict_types=1);

namespace App\Core\Message;

use App\Core\Message\CommonMessageCode;
use InvalidArgumentException;

final readonly class Message
{
    private MessageLevel $level;

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    private function __construct(
        private string $code,
        private string $translationKey,
        private array $parameters = [],
        private array $context = [],
        ?MessageLevel $level = null,
    ) {
        if (!$this->isValidCode($code)) {
            throw new InvalidArgumentException(sprintf('Invalid message code "%s".', $code));
        }

        if (!$this->isValidTranslationKey($translationKey)) {
            throw new InvalidArgumentException(sprintf('Invalid message translation key "%s".', $translationKey));
        }

        $this->assertParameterKeys($parameters);
        $this->level = $level ?? self::defaultLevelForCode($code);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    public static function create(
        string $code,
        string $translationKey,
        array $parameters = [],
        array $context = [],
        ?MessageLevel $level = null,
    ): self {
        return new self($code, $translationKey, $parameters, $context, $level);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    public static function invalidArgument(string $translationKey, array $parameters = [], array $context = []): self
    {
        return new self(CommonMessageCode::E_INVALID_ARGUMENT, $translationKey, $parameters, $context, MessageLevel::Warning);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    public static function error(string $code, string $translationKey, array $parameters = [], array $context = []): self
    {
        return new self($code, $translationKey, $parameters, $context, MessageLevel::Error);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    public static function exception(string $code, string $translationKey, array $parameters = [], array $context = []): self
    {
        return new self($code, $translationKey, $parameters, $context, MessageLevel::Exception);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    public static function warning(string $code, string $translationKey, array $parameters = [], array $context = []): self
    {
        return new self($code, $translationKey, $parameters, $context, MessageLevel::Warning);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    public static function info(string $code, string $translationKey, array $parameters = [], array $context = []): self
    {
        return new self($code, $translationKey, $parameters, $context, MessageLevel::Info);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    public static function debug(string $code, string $translationKey, array $parameters = [], array $context = []): self
    {
        return new self($code, $translationKey, $parameters, $context, MessageLevel::Debug);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    public static function success(string $translationKey, array $parameters = [], array $context = []): self
    {
        return new self(CommonMessageCode::SUCCESS, $translationKey, $parameters, $context, MessageLevel::Success);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function translationKey(): string
    {
        return $this->translationKey;
    }

    public function level(): MessageLevel
    {
        return $this->level;
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
        ], $this->level);
    }

    /**
     * @return array{level: string, code: string, translation_key: string, parameters: array<string, mixed>, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level->value,
            'code' => $this->code,
            'translation_key' => $this->translationKey,
            'parameters' => $this->parameters,
            'context' => $this->context,
        ];
    }

    private static function defaultLevelForCode(string $code): MessageLevel
    {
        if (CommonMessageCode::SUCCESS === $code) {
            return MessageLevel::Success;
        }

        if (CommonMessageCode::E_INVALID_ARGUMENT === $code) {
            return MessageLevel::Warning;
        }

        if (str_starts_with($code, 'E_')) {
            return MessageLevel::Error;
        }

        return MessageLevel::Warning;
    }

    private function isValidCode(string $code): bool
    {
        return 1 === preg_match('/^(?:[A-Z][A-Z0-9_]*|[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+)$/', $code);
    }

    private function isValidTranslationKey(string $translationKey): bool
    {
        return 1 === preg_match(
            '/^(?:message(?:\.[a-z][a-z0-9_]*)+|ext\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*\.(?:[a-z][a-z0-9_]*)(?:\.[a-z][a-z0-9_]*)*)$/',
            $translationKey,
        );
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
