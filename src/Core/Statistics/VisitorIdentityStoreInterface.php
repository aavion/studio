<?php

declare(strict_types=1);

namespace App\Core\Statistics;

interface VisitorIdentityStoreInterface
{
    public function resolve(
        ?string $cookieHash,
        string $fallbackHash,
        ?string $pendingCookieHash,
        string $newVisitorId,
    ): ?string;
}
