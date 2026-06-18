<?php

declare(strict_types=1);

namespace App\Tests\Security\AutoBan;

use App\Security\AutoBan\AutoBanStore;
use App\Security\AutoBan\AutoBanSubject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class AutoBanStoreTest extends TestCase
{
    public function testBanRollsBackActiveStateWhenIndexCannotBeUpdated(): void
    {
        $cache = new IndexFailingAutoBanCache();
        $store = new AutoBanStore($cache, new LockFactory(new InMemoryStore()), clock: new MockClock('2026-06-18 12:00:00'));
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, 'visitor-index-failure');

        self::assertNull($store->ban($subject, 3600));
        self::assertNull($store->active($subject));
        self::assertSame([], $store->activeBans());
    }
}

final class IndexFailingAutoBanCache extends ArrayAdapter
{
    public function save(CacheItemInterface $item): bool
    {
        if ('security.auto_ban.index.v1' === $item->getKey()) {
            return false;
        }

        return parent::save($item);
    }
}
