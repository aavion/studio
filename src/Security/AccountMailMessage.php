<?php

declare(strict_types=1);

namespace App\Security;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;
use Stringable;
use UnitEnum;

final readonly class AccountMailMessage
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        private AccountMailFlow $flow,
        private ?string $recipientEmail,
        private string $locale,
        private array $parameters,
        private ?string $actionUrl = null,
        private ?string $debugPlainToken = null,
        private ?string $tokenUid = null,
        private ?string $tokenType = null,
    ) {
        $this->assertLocale($locale);

        if (null !== $recipientEmail && false === filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(sprintf('Invalid mail recipient "%s".', $recipientEmail));
        }

        foreach (array_keys($parameters) as $parameter) {
            $this->assertParameterKey((string) $parameter);
        }
    }

    public function flow(): AccountMailFlow
    {
        return $this->flow;
    }

    public function recipientEmail(): ?string
    {
        return null === $this->recipientEmail ? null : strtolower($this->recipientEmail);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * @return array<string, string>
     */
    public function parameters(): array
    {
        $parameters = [];

        foreach ($this->parameters as $key => $value) {
            if (null === $value) {
                continue;
            }

            $parameters[$key] = $this->stringValue($value);
        }

        if (null !== $this->actionUrl && !isset($parameters['action_url'])) {
            $parameters['action_url'] = $this->actionUrl;
        }

        if (null !== $this->recipientEmail && !isset($parameters['email'])) {
            $parameters['email'] = strtolower($this->recipientEmail);
        }

        ksort($parameters);

        return $parameters;
    }

    public function actionUrl(): ?string
    {
        return $this->actionUrl;
    }

    public function debugPlainToken(): ?string
    {
        return $this->debugPlainToken;
    }

    public function tokenUid(): ?string
    {
        return $this->tokenUid;
    }

    public function tokenType(): ?string
    {
        return $this->tokenType;
    }

    private function stringValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException(sprintf('Mail parameter values must be scalar or stringable, "%s" given.', get_debug_type($value)));
    }

    private function assertLocale(string $locale): void
    {
        if (1 !== preg_match('/^[a-z][a-z0-9]*(?:[_-][a-zA-Z0-9]+)*$/', $locale)) {
            throw new InvalidArgumentException(sprintf('Invalid mail locale "%s".', $locale));
        }
    }

    private function assertParameterKey(string $key): void
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new InvalidArgumentException(sprintf('Invalid mail parameter key "%s".', $key));
        }
    }
}
