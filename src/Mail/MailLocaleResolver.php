<?php

declare(strict_types=1);

namespace App\Mail;

use App\Content\Routing\ContentRouteLocalization;
use App\Entity\UserAccount;
use Symfony\Component\HttpFoundation\Request;

final readonly class MailLocaleResolver
{
    public function __construct(private ContentRouteLocalization $localization)
    {
    }

    public function forPublicRequest(Request $request, ?UserAccount $user = null): string
    {
        return $this->userLocale($user) ?? $this->supportedLocale($request->getLocale());
    }

    public function forAdminAction(?UserAccount $user = null): string
    {
        return $this->userLocale($user) ?? $this->localization->defaultLanguage();
    }

    public function defaultLocale(): string
    {
        return $this->localization->defaultLanguage();
    }

    private function userLocale(?UserAccount $user): ?string
    {
        if (!$user instanceof UserAccount) {
            return null;
        }

        $language = $user->settings()['language'] ?? null;

        if (!is_string($language) || '' === trim($language) || 'default' === $language) {
            return null;
        }

        return $this->supportedLocale($language);
    }

    private function supportedLocale(string $locale): string
    {
        $availableLanguages = $this->localization->availableLanguages();

        if (in_array($locale, $availableLanguages, true)) {
            return $locale;
        }

        $normalized = strtolower(str_replace('_', '-', $locale));
        $primary = explode('-', $normalized)[0] ?? '';

        foreach ($availableLanguages as $availableLanguage) {
            if ($normalized === strtolower(str_replace('_', '-', $availableLanguage))) {
                return $availableLanguage;
            }
        }

        foreach ($availableLanguages as $availableLanguage) {
            if ($primary === strtolower(str_replace('_', '-', $availableLanguage))) {
                return $availableLanguage;
            }
        }

        return $this->localization->defaultLanguage();
    }
}
