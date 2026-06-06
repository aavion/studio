<?php

declare(strict_types=1);

namespace App\Localization;

use App\Entity\UserAccount;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\LocaleSwitcher;

final readonly class UserProfileLocaleService
{
    public function __construct(
        private LocalePreferenceResolver $localePreferences,
        private LocaleSwitcher $localeSwitcher,
    ) {
    }

    public function apply(Request $request, UserAccount $user): void
    {
        $locale = $this->localePreferences->resolveProfileLocale($user);

        $request->setLocale($locale);

        try {
            $request->getSession()->set('_locale', $locale);
        } catch (SessionNotFoundException) {
        }

        $this->localeSwitcher->setLocale($locale);
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->localePreferences->availableLocales() as $locale) {
            $label = \Locale::getDisplayName($locale, $locale);
            $options[$locale] = '' !== $label ? $label : $locale;
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public function availableLocales(): array
    {
        return $this->localePreferences->availableLocales();
    }
}
