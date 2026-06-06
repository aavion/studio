<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class FormTokenValidator
{
    public function __construct(private CsrfTokenManagerInterface $csrfTokenManager)
    {
    }

    public function isValid(string $expectedFormId, string $formId, string $token): bool
    {
        return $expectedFormId === $formId && $this->csrfTokenManager->isTokenValid(new CsrfToken($expectedFormId, $token));
    }
}
