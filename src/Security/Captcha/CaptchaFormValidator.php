<?php

declare(strict_types=1);

namespace App\Security\Captcha;

use Symfony\Component\HttpFoundation\Request;

final readonly class CaptchaFormValidator
{
    public function result(Request $request): CaptchaResult
    {
        $result = $request->attributes->get(CaptchaRequestGuardSubscriber::RESULT_ATTRIBUTE);
        if ($result instanceof CaptchaResult) {
            return $result;
        }

        if (is_string($result)) {
            return CaptchaResult::tryFrom($result) ?? CaptchaResult::Failed;
        }

        $bodyResult = $request->request->all()[CaptchaRequestGuardSubscriber::RESULT_FIELD] ?? null;

        return is_string($bodyResult) ? CaptchaResult::tryFrom($bodyResult) ?? CaptchaResult::Failed : CaptchaResult::Failed;
    }

    public function acceptsRequired(Request $request): bool
    {
        return in_array($this->result($request), [CaptchaResult::Skipped, CaptchaResult::Verified], true);
    }

    public function acceptsVerified(Request $request): bool
    {
        return CaptchaResult::Verified === $this->result($request);
    }
}
