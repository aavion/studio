<?php

declare(strict_types=1);

namespace App\Privacy\Cookie;

interface CookieConsentProviderInterface
{
    /**
     * @return list<CookieConsentDefinition>
     */
    public function cookieConsentDefinitions(): array;
}
