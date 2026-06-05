<?php

declare(strict_types=1);

namespace App\Mail;

use App\Entity\UserAccount;
use App\Localization\LocalePreferenceResolver;
use Symfony\Component\HttpFoundation\Request;

final readonly class MailLocaleResolver
{
    public function __construct(private LocalePreferenceResolver $localePreferences)
    {
    }

    public function forPublicRequest(Request $request, ?UserAccount $user = null): string
    {
        return $this->localePreferences->resolveMailLocale($user, $request->getLocale());
    }

    public function forAdminAction(?UserAccount $user = null): string
    {
        return $this->localePreferences->resolveMailLocale($user);
    }

    public function defaultLocale(): string
    {
        return $this->localePreferences->defaultLocale();
    }
}
