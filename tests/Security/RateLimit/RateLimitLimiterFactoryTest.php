<?php

declare(strict_types=1);

namespace App\Tests\Security\RateLimit;

use App\Security\RateLimit\RateLimitBucketDescriptor;
use App\Security\RateLimit\RateLimitLimiterFactory;
use App\Security\RateLimit\RateLimitPolicyCatalogue;
use App\Security\RateLimit\RateLimitProfile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

final class RateLimitLimiterFactoryTest extends TestCase
{
    public function testPersistedLimiterStateIsIsolatedByScaledDescriptorShape(): void
    {
        $catalogue = new RateLimitPolicyCatalogue();
        $standard = $catalogue->descriptor('website.deliberate.burst', RateLimitProfile::Standard);
        $panic = $catalogue->descriptor('website.deliberate.burst', RateLimitProfile::Panic);
        self::assertInstanceOf(RateLimitBucketDescriptor::class, $standard);
        self::assertInstanceOf(RateLimitBucketDescriptor::class, $panic);

        $factory = new RateLimitLimiterFactory(new ArrayAdapter());
        $subjectKey = 'website.deliberate.burst:visitor:profile-isolation';

        for ($i = 0; $i < $panic->limit() - 1; ++$i) {
            self::assertTrue($factory->consume($standard, $subjectKey, 1));
        }

        for ($i = 0; $i < $panic->limit(); ++$i) {
            self::assertTrue($factory->consume($panic, $subjectKey, 1));
        }

        self::assertInstanceOf(\DateTimeImmutable::class, $factory->consume($panic, $subjectKey, 1));
    }

    public function testConsumeUsesConfiguredLockFactory(): void
    {
        $catalogue = new RateLimitPolicyCatalogue();
        $descriptor = $catalogue->descriptor('login.failure', RateLimitProfile::Standard);
        self::assertInstanceOf(RateLimitBucketDescriptor::class, $descriptor);

        $lockFactory = new TrackingRateLimitLockFactory();
        $factory = new RateLimitLimiterFactory(new ArrayAdapter(), $lockFactory);

        self::assertTrue($factory->consume($descriptor, 'login.failure:visitor:lock-test', 1));
        self::assertGreaterThanOrEqual(1, $lockFactory->createdLocks);
    }

    public function testAcceptsChecksCapacityWithoutSpendingCredits(): void
    {
        $catalogue = new RateLimitPolicyCatalogue();
        $descriptor = $catalogue->descriptor('scheduler.interval', RateLimitProfile::Standard);
        self::assertInstanceOf(RateLimitBucketDescriptor::class, $descriptor);

        $factory = new RateLimitLimiterFactory(new ArrayAdapter());
        $subjectKey = 'scheduler.interval:visitor:accepts-test';

        self::assertTrue($factory->accepts($descriptor, $subjectKey, 1));
        self::assertTrue($factory->accepts($descriptor, $subjectKey, 1));
        self::assertTrue($factory->consume($descriptor, $subjectKey, 1));
        self::assertInstanceOf(\DateTimeImmutable::class, $factory->accepts($descriptor, $subjectKey, 1));
    }
}

final class TrackingRateLimitLockFactory extends LockFactory
{
    public int $createdLocks = 0;

    public function __construct()
    {
        parent::__construct(new InMemoryStore());
    }

    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        ++$this->createdLocks;

        return parent::createLock($resource, $ttl, $autoRelease);
    }
}
