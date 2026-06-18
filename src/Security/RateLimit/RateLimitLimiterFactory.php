<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

final class RateLimitLimiterFactory
{
    /** @var array<string, RateLimiterFactory> */
    private array $factories = [];

    public function __construct(
        private readonly CacheItemPoolInterface $cachePool,
        private readonly ?LockFactory $lockFactory = null,
    ) {
    }

    public function consume(RateLimitBucketDescriptor $descriptor, string $subjectKey, int $credits): \DateTimeImmutable|true
    {
        $limit = $this->factory($descriptor)->create($subjectKey)->consume($credits);

        return $limit->isAccepted() ? true : $limit->getRetryAfter();
    }

    public function accepts(RateLimitBucketDescriptor $descriptor, string $subjectKey, int $credits): \DateTimeImmutable|true
    {
        $limit = $this->factory($descriptor)->create($subjectKey)->consume(0);

        return $limit->getRemainingTokens() >= $credits ? true : $limit->getRetryAfter();
    }

    public function reset(RateLimitBucketDescriptor $descriptor, string $subjectKey): void
    {
        $this->factory($descriptor)->create($subjectKey)->reset();
    }

    private function factory(RateLimitBucketDescriptor $descriptor): RateLimiterFactory
    {
        $key = implode('|', [
            $descriptor->name(),
            (string) $descriptor->limit(),
            (string) $descriptor->windowSeconds(),
        ]);

        return $this->factories[$key] ??= new RateLimiterFactory([
            'id' => implode('.', [
                'system.rate',
                $descriptor->name(),
                (string) $descriptor->limit(),
                (string) $descriptor->windowSeconds(),
            ]),
            'policy' => 'fixed_window',
            'limit' => $descriptor->limit(),
            'interval' => $descriptor->windowSeconds().' seconds',
        ], new CacheStorage($this->cachePool), $this->lockFactory);
    }
}
