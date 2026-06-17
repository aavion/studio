<?php

declare(strict_types=1);

namespace App\Tests\Security\RateLimit;

use App\Security\RateLimit\RateLimitPolicyCatalogue;
use App\Security\RateLimit\RateLimitProfile;
use PHPUnit\Framework\TestCase;

final class RateLimitPolicyCatalogueTest extends TestCase
{
    public function testItExposesStandardPolicyDescriptors(): void
    {
        $catalogue = new RateLimitPolicyCatalogue();

        $login = $catalogue->descriptor('login.failure');
        $burst = $catalogue->descriptor('website.deliberate.burst');
        $probe = $catalogue->descriptor('suspicious.probe');
        $captcha = $catalogue->descriptor('captcha.failure');

        self::assertNotNull($login);
        self::assertSame('login', $login->bucketFamily());
        self::assertSame(5, $login->limit());
        self::assertSame(900, $login->windowSeconds());
        self::assertTrue($login->resettable());
        self::assertNotNull($burst);
        self::assertSame(30, $burst->limit());
        self::assertSame(60, $burst->windowSeconds());
        self::assertNotNull($probe);
        self::assertSame(1, $probe->limit());
        self::assertSame(600, $probe->windowSeconds());
        self::assertNotNull($captcha);
        self::assertTrue($captcha->resettable());
    }

    public function testStrictAndPanicProfilesDeriveFromStandardDescriptors(): void
    {
        $catalogue = new RateLimitPolicyCatalogue();

        $standard = $catalogue->descriptor('website.deliberate.burst', RateLimitProfile::Standard);
        $strict = $catalogue->descriptor('website.deliberate.burst', RateLimitProfile::Strict);
        $panic = $catalogue->descriptor('website.deliberate.burst', RateLimitProfile::Panic);

        self::assertNotNull($standard);
        self::assertNotNull($strict);
        self::assertNotNull($panic);
        self::assertSame(30, $standard->limit());
        self::assertSame(60, $standard->windowSeconds());
        self::assertSame(15, $strict->limit());
        self::assertSame(90, $strict->windowSeconds());
        self::assertSame(7, $panic->limit());
        self::assertSame(120, $panic->windowSeconds());
    }

    public function testNonScalableBucketsStayStableAcrossProfiles(): void
    {
        $catalogue = new RateLimitPolicyCatalogue();

        $standard = $catalogue->descriptor('suspicious.probe', RateLimitProfile::Standard);
        $panic = $catalogue->descriptor('suspicious.probe', RateLimitProfile::Panic);

        self::assertNotNull($standard);
        self::assertNotNull($panic);
        self::assertSame($standard->limit(), $panic->limit());
        self::assertSame($standard->windowSeconds(), $panic->windowSeconds());
    }

    public function testOffProfileDoesNotConsumeLimiterStorage(): void
    {
        self::assertFalse(RateLimitProfile::Off->consumesLimiterStorage());
        self::assertTrue(RateLimitProfile::Standard->consumesLimiterStorage());
        self::assertSame(RateLimitProfile::Standard, RateLimitProfile::fromMixed('unknown'));
    }
}
