<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AccountToken;

interface AccountLinkDeliveryInterface
{
    public function deliver(AccountToken $token, string $plainToken, string $url): void;
}
