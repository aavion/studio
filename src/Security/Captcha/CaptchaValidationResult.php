<?php

declare(strict_types=1);

namespace App\Security\Captcha;

final readonly class CaptchaValidationResult
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        private CaptchaValidationStatus $status,
        private ?string $provider,
        private array $context = [],
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function skipped(?string $provider = null, array $context = []): self
    {
        return new self(CaptchaValidationStatus::Skipped, $provider, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function verifiedForProvider(string $provider, array $context = []): self
    {
        return new self(CaptchaValidationStatus::Verified, $provider, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function recoverableFailure(?string $provider = null, array $context = []): self
    {
        return new self(CaptchaValidationStatus::RecoverableFailure, $provider, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function suspiciousFailure(?string $provider = null, array $context = []): self
    {
        return new self(CaptchaValidationStatus::SuspiciousFailure, $provider, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function providerUnavailable(?string $provider = null, array $context = []): self
    {
        return new self(CaptchaValidationStatus::ProviderUnavailable, $provider, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function providerFault(?string $provider = null, array $context = []): self
    {
        return new self(CaptchaValidationStatus::ProviderFault, $provider, $context);
    }

    public function status(): CaptchaValidationStatus
    {
        return $this->status;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function withProvider(?string $provider): self
    {
        return new self($this->status, $provider, $this->context);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function isVerified(): bool
    {
        return CaptchaValidationStatus::Verified === $this->status;
    }

    public function captchaResult(): CaptchaResult
    {
        return match ($this->status) {
            CaptchaValidationStatus::Skipped => CaptchaResult::Skipped,
            CaptchaValidationStatus::Verified => CaptchaResult::Verified,
            default => CaptchaResult::Failed,
        };
    }

    public function isProviderBacked(): bool
    {
        return null !== $this->provider && '' !== trim($this->provider) && 'none' !== strtolower($this->provider);
    }
}
