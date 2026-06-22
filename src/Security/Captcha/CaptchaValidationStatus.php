<?php

declare(strict_types=1);

namespace App\Security\Captcha;

enum CaptchaValidationStatus: string
{
    case Skipped = 'skipped';
    case Verified = 'verified';
    case RecoverableFailure = 'recoverable_failure';
    case SuspiciousFailure = 'suspicious_failure';
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderFault = 'provider_fault';
}
