<?php

declare(strict_types=1);

namespace App\Security;

enum AccountTokenType: string
{
    case Invitation = 'invitation';
    case Registration = 'registration';
    case PasswordReset = 'password_reset';
    case SecurityReview = 'security_review';
}
