<?php

declare(strict_types=1);

namespace App\View\Alert;

interface UiAlertUserIdentityResolverInterface
{
    public function resolveUid(string $identifier): ?string;
}
