<?php

declare(strict_types=1);

namespace App\Privacy\Cookie;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CookieConsentTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly CookieConsentRegistry $registry,
        private readonly CookieConsentManager $consent,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cookie_consent_required', $this->required(...)),
            new TwigFunction('cookie_consent_form_required', $this->formRequired(...)),
            new TwigFunction('cookie_consent_csrf_token', $this->csrfToken(...)),
            new TwigFunction('cookie_consent_optional', $this->optional(...)),
            new TwigFunction('cookie_consent_default_selected', $this->defaultSelected(...)),
            new TwigFunction('cookie_consent_selected_names', $this->selectedNames(...)),
            new TwigFunction('cookie_consent_trigger_attributes', $this->triggerAttributes(...)),
        ];
    }

    public function required(): bool
    {
        $request = $this->requestStack->getMainRequest();

        return null !== $request && $this->consent->bannerRequired($request);
    }

    public function formRequired(): bool
    {
        $request = $this->requestStack->getMainRequest();

        return null !== $request && $this->consent->formRequired($request);
    }

    public function csrfToken(): string
    {
        return $this->consent->csrfToken();
    }

    /**
     * @return list<array{name: string, provider: string, purpose: string, privacy_url: string}>
     */
    public function optional(): array
    {
        return array_map(
            static fn (CookieConsentDefinition $definition): array => [
                'name' => $definition->name(),
                'provider' => $definition->provider(),
                'purpose' => $definition->purpose(),
                'privacy_url' => $definition->privacyUrl(),
            ],
            $this->registry->optionalDefinitions(),
        );
    }

    public function defaultSelected(): bool
    {
        $request = $this->requestStack->getMainRequest();

        return null !== $request && $this->consent->defaultOptionalSelected($request);
    }

    /**
     * @return list<string>
     */
    public function selectedNames(): array
    {
        $request = $this->requestStack->getMainRequest();

        return null !== $request ? $this->consent->selectedOptionalNames($request) : [];
    }

    /**
     * @return array<string, string|bool>
     */
    public function triggerAttributes(): array
    {
        return [
            'aria-controls' => 'cookie-consent',
            'data-cookie-consent-open' => true,
        ];
    }
}
