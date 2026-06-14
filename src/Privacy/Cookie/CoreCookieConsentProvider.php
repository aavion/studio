<?php

declare(strict_types=1);

namespace App\Privacy\Cookie;

use App\Core\Statistics\VisitorIdGenerator;
use Symfony\Component\HttpFoundation\Cookie;

final readonly class CoreCookieConsentProvider implements CookieConsentProviderInterface
{
    public function cookieConsentDefinitions(): array
    {
        return [
            CookieConsentDefinition::necessary(Cookie::create(CookieConsentManager::CONSENT_COOKIE_NAME)),
            CookieConsentDefinition::necessary(Cookie::create('PHPSESSID')),
            CookieConsentDefinition::necessary(Cookie::create(VisitorIdGenerator::COOKIE_NAME)),
            CookieConsentDefinition::optional(
                Cookie::create('_ga'),
                'Google Analytics',
                'Measures visits and interaction patterns to improve the site experience.',
                'https://policies.google.com/privacy',
            ),
        ];
    }
}
