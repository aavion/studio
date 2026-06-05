<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AccountToken;
use App\Mail\AccountMailFlow;

interface AccountLinkDeliveryInterface
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function deliver(AccountToken $token, AccountMailFlow $flow, string $plainToken, string $url, string $locale, array $parameters = []): void;

    /**
     * @param array<string, mixed> $parameters
     */
    public function notify(AccountToken $token, AccountMailFlow $flow, ?string $recipientEmail = null, string $locale = '', array $parameters = []): void;

    /**
     * @param array<string, mixed> $parameters
     */
    public function notifyAddress(string $recipientEmail, AccountMailFlow $flow, string $locale, array $parameters = []): void;
}
