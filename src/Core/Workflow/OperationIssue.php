<?php

declare(strict_types=1);

namespace App\Core\Workflow;

use InvalidArgumentException;

final readonly class OperationIssue
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $code,
        private string $message,
        private array $context = [],
    ) {
        if ('' === trim($code)) {
            throw new InvalidArgumentException('Operation issue code must not be empty.');
        }

        if ('' === trim($message)) {
            throw new InvalidArgumentException('Operation issue message must not be empty.');
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function create(string $code, string $message, array $context = []): self
    {
        return new self($code, $message, $context);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @return array{code: string, message: string, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
