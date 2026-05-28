<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AccountToken;

interface AccountLinkDeliveryInterface
{
    public function deliver(AccountToken $token, AccountMailFlow $flow, string $plainToken, string $url): void;

    /**
     * @param array<string, mixed> $context
     */
    public function notify(AccountToken $token, AccountMailFlow $flow, ?string $recipientEmail = null, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function notifyAddress(string $recipientEmail, AccountMailFlow $flow, array $context = []): void;
}
