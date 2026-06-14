<?php

declare(strict_types=1);

namespace App\Privacy\Cookie;

final readonly class CookieConsentRegistry
{
    /**
     * @param iterable<CookieConsentProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    /**
     * @return list<CookieConsentDefinition>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->cookieConsentDefinitions() as $definition) {
                $definitions[$definition->name()] = $definition;
            }
        }

        ksort($definitions);

        return array_values($definitions);
    }

    /**
     * @return list<CookieConsentDefinition>
     */
    public function optionalDefinitions(): array
    {
        return array_values(array_filter(
            $this->definitions(),
            static fn (CookieConsentDefinition $definition): bool => !$definition->isNecessary(),
        ));
    }

    public function definition(string $name): ?CookieConsentDefinition
    {
        foreach ($this->definitions() as $definition) {
            if ($definition->name() === $name) {
                return $definition;
            }
        }

        return null;
    }
}
