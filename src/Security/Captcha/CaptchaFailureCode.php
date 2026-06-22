<?php

declare(strict_types=1);

namespace App\Security\Captcha;

enum CaptchaFailureCode: string
{
    case ProviderRuntimeFailed = 'provider_runtime_failed';
    case ProviderResultInvalid = 'provider_result_invalid';
}
