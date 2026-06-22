<?php

declare(strict_types=1);

namespace App\Security\Captcha;

final readonly class CaptchaRenderResult
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        private string $template,
        private array $context,
        private ?string $provider,
        private bool $visible,
        private bool $faulty = false,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function forProvider(string $provider, array $context = [], string $template = '@provider/captcha/field.html.twig'): self
    {
        return new self($template, $context, $provider, true);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function fallback(array $context = [], string $template = '@provider/captcha/field.html.twig'): self
    {
        return new self($template, $context, null, false);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function providerFault(?string $provider = null, array $context = [], string $template = '@provider/captcha/field.html.twig'): self
    {
        return new self($template, $context, $provider, false, true);
    }

    public function template(): string
    {
        return $this->template;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function withProvider(?string $provider): self
    {
        return new self($this->template, $this->context, $provider, $this->visible, $this->faulty);
    }

    public function visible(): bool
    {
        return $this->visible;
    }

    public function faulty(): bool
    {
        return $this->faulty;
    }
}
