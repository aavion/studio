<?php

declare(strict_types=1);

namespace App\Security\Captcha;

enum CaptchaResult: string
{
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Verified = 'verified';
}
