<?php

declare(strict_types=1);

namespace App\Privacy\Cookie;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Cookie;

final readonly class CookieConsentDefinition
{
    public function __construct(
        private Cookie $cookie,
        private bool $necessary = false,
        private string $provider = '',
        private string $purpose = '',
        private string $privacyUrl = '',
    ) {
        if ('' === trim($cookie->getName())) {
            throw new InvalidArgumentException('Cookie consent definitions require a cookie name.');
        }

        if (!$necessary && ('' === trim($provider) || '' === trim($purpose) || '' === trim($privacyUrl))) {
            throw new InvalidArgumentException('Optional cookies require provider, purpose, and privacy URL metadata.');
        }
    }

    public static function necessary(Cookie $cookie): self
    {
        return new self($cookie, true);
    }

    public static function optional(Cookie $cookie, string $provider, string $purpose, string $privacyUrl): self
    {
        return new self($cookie, false, $provider, $purpose, $privacyUrl);
    }

    public function cookie(): Cookie
    {
        return $this->cookie;
    }

    public function name(): string
    {
        return $this->cookie->getName();
    }

    public function isNecessary(): bool
    {
        return $this->necessary;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function privacyUrl(): string
    {
        return $this->privacyUrl;
    }
}
