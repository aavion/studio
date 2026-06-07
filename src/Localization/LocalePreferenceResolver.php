<?php

declare(strict_types=1);

namespace App\Localization;

use App\Content\Routing\ContentRouteLocalization;
use App\Entity\UserAccount;

final readonly class LocalePreferenceResolver
{
    public function __construct(private ContentRouteLocalization $localization)
    {
    }

    public function defaultLocale(): string
    {
        return $this->localization->defaultLanguage();
    }

    /**
     * @return list<string>
     */
    public function availableLocales(): array
    {
        return $this->localization->availableLanguages();
    }

    public function resolveRequestLocale(
        ?string $urlLocale,
        ?UserAccount $user,
        ?string $sessionLocale,
        ?string $preferredLocale = null,
    ): ?string
    {
        return $this->firstSupported($urlLocale)
            ?? $this->firstSupportedLenient(
                $sessionLocale,
                $this->userPreference($user),
                $preferredLocale,
                $this->defaultLocale(),
            );
    }

    public function resolveMailLocale(?UserAccount $user = null, ?string $requestLocale = null): string
    {
        return $this->firstSupportedLenient($this->userPreference($user), $requestLocale, $this->defaultLocale())
            ?? $this->defaultLocale();
    }

    public function resolveProfileLocale(UserAccount $user): string
    {
        return $this->firstSupportedLenient($this->userPreference($user), $this->defaultLocale())
            ?? $this->defaultLocale();
    }

    private function userPreference(?UserAccount $user): ?string
    {
        if (!$user instanceof UserAccount) {
            return null;
        }

        $language = $user->settings()['language'] ?? null;

        if (!is_string($language) || '' === trim($language) || 'default' === $language) {
            return null;
        }

        return trim($language);
    }

    private function firstSupported(?string ...$candidates): ?string
    {
        $availableLanguages = $this->localization->availableLanguages();

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array(trim($candidate), $availableLanguages, true)) {
                return trim($candidate);
            }
        }

        return null;
    }

    private function firstSupportedLenient(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $supported = $this->firstSupported($candidate);

            if (null !== $supported) {
                return $supported;
            }

            if (!is_string($candidate) || '' === trim($candidate)) {
                continue;
            }

            $normalized = strtolower(str_replace('_', '-', trim($candidate)));
            $primary = explode('-', $normalized)[0] ?? '';

            foreach ($this->localization->availableLanguages() as $availableLanguage) {
                if ($normalized === strtolower(str_replace('_', '-', $availableLanguage))) {
                    return $availableLanguage;
                }
            }

            foreach ($this->localization->availableLanguages() as $availableLanguage) {
                if ($primary === strtolower(str_replace('_', '-', $availableLanguage))) {
                    return $availableLanguage;
                }
            }
        }

        return null;
    }
}
