<?php

declare(strict_types=1);

namespace App\Tests\Security\Captcha;

use App\Security\Captcha\CaptchaFormValidator;
use App\Security\Captcha\CaptchaRequestGuardSubscriber;
use App\Security\Captcha\CaptchaResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CaptchaFormValidatorTest extends TestCase
{
    public function testItFailsWhenNoServerSideCaptchaResultExists(): void
    {
        $validator = new CaptchaFormValidator();

        self::assertSame(CaptchaResult::Failed, $validator->result(Request::create('/user/register', 'POST')));
    }

    public function testItReadsServerSideRequestAttributeBeforeBodyField(): void
    {
        $validator = new CaptchaFormValidator();
        $request = Request::create('/user/register', 'POST', [
            CaptchaRequestGuardSubscriber::RESULT_FIELD => 'verified',
        ]);
        $request->attributes->set(CaptchaRequestGuardSubscriber::RESULT_ATTRIBUTE, 'failed');

        self::assertSame(CaptchaResult::Failed, $validator->result($request));
        self::assertFalse($validator->acceptsRequired($request));
        self::assertFalse($validator->acceptsVerified($request));
    }

    public function testRequiredPolicyAcceptsSkippedAndVerifiedResults(): void
    {
        $validator = new CaptchaFormValidator();

        foreach ([CaptchaResult::Skipped, CaptchaResult::Verified] as $result) {
            $request = Request::create('/user/register', 'POST');
            $request->attributes->set(CaptchaRequestGuardSubscriber::RESULT_ATTRIBUTE, $result);

            self::assertTrue($validator->acceptsRequired($request));
        }
    }

    public function testVerifiedPolicyAcceptsOnlyVerifiedResults(): void
    {
        $validator = new CaptchaFormValidator();
        $skipped = Request::create('/user/register', 'POST');
        $skipped->attributes->set(CaptchaRequestGuardSubscriber::RESULT_ATTRIBUTE, CaptchaResult::Skipped);
        $verified = Request::create('/user/register', 'POST');
        $verified->attributes->set(CaptchaRequestGuardSubscriber::RESULT_ATTRIBUTE, CaptchaResult::Verified);

        self::assertFalse($validator->acceptsVerified($skipped));
        self::assertTrue($validator->acceptsVerified($verified));
    }
}
