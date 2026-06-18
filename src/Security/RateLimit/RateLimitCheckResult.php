<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

final readonly class RateLimitCheckResult
{
    private function __construct(
        private bool $allowed,
        private bool $suspiciousProbe,
        private bool $storageDegraded,
        private ?int $retryAfterSeconds,
        private ?string $diagnosticsLabel,
    ) {
    }

    public static function allow(bool $storageDegraded = false): self
    {
        return new self(true, false, $storageDegraded, null, null);
    }

    public static function reject(?int $retryAfterSeconds, string $diagnosticsLabel): self
    {
        return new self(false, false, false, $retryAfterSeconds, $diagnosticsLabel);
    }

    public static function blockSuspiciousProbe(bool $storageDegraded = false): self
    {
        return new self(false, true, $storageDegraded, null, 'security.rate.suspicious_probe');
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function suspiciousProbe(): bool
    {
        return $this->suspiciousProbe;
    }

    public function storageDegraded(): bool
    {
        return $this->storageDegraded;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function diagnosticsLabel(): ?string
    {
        return $this->diagnosticsLabel;
    }
}
