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
        self::assertSame(10, $probe->limit());
        self::assertSame(10, $probe->minimumLimit());
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
        self::assertSame(16, $strict->limit());
        self::assertSame(90, $strict->windowSeconds());
        self::assertSame(16, $panic->limit());
        self::assertSame(120, $panic->windowSeconds());
    }

    public function testRecoveryBucketsStayStableAcrossProfiles(): void
    {
        $catalogue = new RateLimitPolicyCatalogue();

        $standard = $catalogue->descriptor('recovery.login.minute', RateLimitProfile::Standard);
        $panic = $catalogue->descriptor('recovery.login.minute', RateLimitProfile::Panic);

        self::assertNotNull($standard);
        self::assertNotNull($panic);
        self::assertSame($standard->limit(), $panic->limit());
        self::assertSame($standard->windowSeconds(), $panic->windowSeconds());
    }

    public function testProbeScalingKeepsOneActionFloorWhileExtendingWindow(): void
    {
        $catalogue = new RateLimitPolicyCatalogue();

        $strict = $catalogue->descriptor('suspicious.probe', RateLimitProfile::Strict);
        $panic = $catalogue->descriptor('suspicious.probe', RateLimitProfile::Panic);

        self::assertNotNull($strict);
        self::assertNotNull($panic);
        self::assertSame(10, $strict->limit());
        self::assertSame(900, $strict->windowSeconds());
        self::assertSame(10, $panic->limit());
        self::assertSame(1200, $panic->windowSeconds());
    }

    public function testPolicyUsesActionCostsAsCreditMultipliers(): void
    {
        $catalogue = new RateLimitPolicyCatalogue();

        $registration = $catalogue->descriptor('registration.hour');
        $apiWrite = $catalogue->descriptor('api.write');

        self::assertNotNull($registration);
        self::assertSame(15, $registration->limit());
        self::assertSame(10, $registration->minimumLimit());
        self::assertNotNull($apiWrite);
        self::assertSame(300, $apiWrite->limit());
        self::assertSame(10, $apiWrite->minimumLimit());
    }

    public function testProfileScalingKeepsMinimumCostedActionsAvailable(): void
    {
        $catalogue = new RateLimitPolicyCatalogue();

        foreach ($catalogue->descriptors(RateLimitProfile::Panic) as $descriptor) {
            self::assertGreaterThanOrEqual($descriptor->minimumLimit(), $descriptor->limit(), $descriptor->name());
        }
    }

    public function testDescriptorFloorsCoverTwoActionsExceptExplicitIntervalPolicies(): void
    {
        $catalogue = new RateLimitPolicyCatalogue();
        $expectedMinimums = [
            'login.failure' => 2,
            'recovery.login.minute' => 2,
            'recovery.login.hour' => 2,
            'registration.hour' => 10,
            'registration.day' => 10,
            'password_reset.hour' => 6,
            'password_reset.day' => 6,
            'captcha.failure' => 2,
            'website.deliberate.burst' => 16,
            'website.deliberate.sustained' => 16,
            'website.form' => 4,
            'website.prefetch.minute' => 2,
            'website.prefetch.sustained' => 2,
            'api.read' => 2,
            'api.public_read' => 2,
            'api.write' => 10,
            'scheduler.interval' => 1,
            'setup.apply' => 16,
            'admin.mutation' => 16,
            'upload_archive.validation' => 16,
            'download_diagnostics' => 8,
            'suspicious.probe' => 10,
        ];

        foreach ($expectedMinimums as $name => $minimum) {
            $descriptor = $catalogue->descriptor($name);
            self::assertNotNull($descriptor, $name);
            self::assertSame($minimum, $descriptor->minimumLimit(), $name);
        }
    }

    public function testWebsiteBurstFloorCoversTwoHighCostCompanionActions(): void
    {
        $catalogue = new RateLimitPolicyCatalogue();

        $panic = $catalogue->descriptor('website.deliberate.burst', RateLimitProfile::Panic);

        self::assertNotNull($panic);
        self::assertSame(16, $panic->minimumLimit());
        self::assertSame(16, $panic->limit());
    }

    public function testSchedulerProfileIntervalsUseExplicitCronPolicy(): void
    {
        $catalogue = new RateLimitPolicyCatalogue();

        $standard = $catalogue->descriptor('scheduler.interval', RateLimitProfile::Standard);
        $strict = $catalogue->descriptor('scheduler.interval', RateLimitProfile::Strict);
        $panic = $catalogue->descriptor('scheduler.interval', RateLimitProfile::Panic);

        self::assertNotNull($standard);
        self::assertNotNull($strict);
        self::assertNotNull($panic);
        self::assertSame(1, $standard->limit());
        self::assertSame(60, $standard->windowSeconds());
        self::assertSame(1, $strict->limit());
        self::assertSame(900, $strict->windowSeconds());
        self::assertSame(1, $panic->limit());
        self::assertSame(3600, $panic->windowSeconds());
    }

    public function testOffProfileDoesNotConsumeLimiterStorage(): void
    {
        self::assertFalse(RateLimitProfile::Off->consumesLimiterStorage());
        self::assertTrue(RateLimitProfile::Standard->consumesLimiterStorage());
        self::assertSame(RateLimitProfile::Standard, RateLimitProfile::fromMixed('unknown'));
    }
}
